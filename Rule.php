<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\Filterable;
use App\Traits\Uuids;
use App\Facades\Fields;
use App\Roles\Role;
use App\Traits\ClientID;
use Exception;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Venturecraft\Revisionable\Revision;
use Venturecraft\Revisionable\RevisionableTrait;
use App\AwardedCertification;
use App\Roles\SuperAdminRole;

/**
 * @mixin IdeHelperRule
 */
class Rule extends Model
{
    use HasFactory;

    use Uuids;
    use ClientID;
    use RevisionableTrait;
    use Filterable;

    protected $fillable = ['parent_rule_id', 'logical_operator', 'client_id', 'name'];
    public $type = 'rule';

    public $timestamps = false;

    public const PERMISSIONS_TYPE = 'permissions';
    public const ADMIN_TYPE = 'admin';
    public const AUDIENCE_TYPE = 'audience';
    public const PARENT_ROLE_AUDIENCE_SNAPSHOT_TYPE = 'parent_role_audience_snapshot';
    public const ABILITY_TYPE = 'ability';

    /**
     * All sub-rules and their expressions
     * @return mixed
     */
    public function subRules()
    {
        return $this->hasMany(Rule::class, 'parent_rule_id', 'id')->with('expressions', 'subRules');
    }

    /**
     * This rule's parent
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(Rule::class, 'parent_rule_id', 'id');
    }

    /**
     * This rule's expressions
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function expressions()
    {
        return $this->hasMany(Expression::class, 'rule_id', 'id');
    }

    /**
     * Get all of the modules with this rule.
     */
    public function modules()
    {
        return $this->morphedByMany(Module::class, 'permissionable');
    }

    /**
     * Get all of the media with this rule.
     */
    public function media()
    {
        return $this->morphedByMany(ModuleMedia::class, 'permissionable')->with('media');
    }

    /**
     * Get all of the user groups with this rule.
     */
    public function userGroups()
    {
        return $this->morphedByMany(UserGroup::class, 'permissionable');
    }

    /**
     * Get all of the event sessions with this rule.
     */
    public function eventSessions()
    {
        return $this->morphedByMany(EventSession::class, 'permissionable');
    }


    /**
     * Get the permissionable records using this rule
     * @return mixed
     */
    public function getPermissionables()
    {
        return DB::table('permissionables')->where('rule_id', $this->id)->get();
    }

    /**
     * @param $user
     * Does the given user pass the rule.
     *
     * @param null $admin
     * @return boolean
     */
    public function evaluate($user, $admin = null)
    {
        return User::setEagerLoads([])->withoutGlobalScopes()->addSelect('users.id as user_id')->where(function ($query) use ($admin) {
            $this->buildRuleSql($query, $admin);
        })->where('users.id', $user->id)->exists();
    }

    /**
     * Does the given user pass the rule .. using just logic rather than sql
     * ***INTERNAL TEST USE ONLY ****
     */
    public function evaluateV2(User $user, ?User $admin = null) : bool
    {
        $match = true;
        $inverse = ($this->logical_operator == LogicalOperator::AND);
        if ($this->expressions->isNotEmpty()) {
            //evaluate the expressions on this rule
            try {
                //find the first expression that is either failed if "ALL ARE TRUE" or successful if "ANY ARE TRUE"
                $this->expressions->firstOrFail(function (Expression $e) use ($inverse, $user, $admin) {
                    return $inverse ? ! $e->matches($user, $admin) : $e->matches($user, $admin);
                });
                $match = $inverse ? false : true;
            } catch(\Illuminate\Support\ItemNotFoundException $nfe) {
                //there was no matching "definitive" expression
                $match = $inverse ? true : false;
            }
        }
        // no point in processing sub rules if we want "ALL TO BE TRUE" and have already failed
        if ($this->logical_operator == LogicalOperator::AND && !$match) {
            return false;
        }

        if ($this->subRules->isNotEmpty()) {
            try {
                //find the first RULE that is either failed if "ALL ARE TRUE" or successful if "ANY ARE TRUE"
                $this->subRules->firstOrFail(function (Rule $r) use ($inverse, $user, $admin) {
                    return $inverse ? ! $r->evaluateV2($user, $admin) : $r->evaluateV2($user, $admin);
                });
                $match = $inverse ? false : true;
            } catch(\Illuminate\Support\ItemNotFoundException $nfe) {
                //there was no matching "definitive" expression
                $match = $inverse ? true : false;
            }
        }
        return $match;
    }

    /**
     * Uses a constructed SQL statement to fetch collection of users that match this rule
     * @return mixed
     */
    public function getUsers()
    {
        return User::select('users.*')->ruleFilter($this)->get();
    }

    /**
     * Check to see if rule has at least one expression on self or any child rules
     * @return boolean true if at least one expression present in rule tree, false if not
     */
    public function hasExpressions()
    {
        return $this->expressions->count() > 0
            || ($this->subRules->count() > 0 && !$this->subRules->every(function ($rule) {
                return !$rule->hasExpressions();
            }));
    }

    /**
     * Construct SQL statement from Rule
     * @param null $builder
     * @param null $admin_user
     * @return null $builder
     */
    public function buildRuleSql($builder = null, $admin_user = null)
    {
        if (is_null($builder)) {
            $builder = User::setEagerLoads([])->withoutGlobalScopes()->addSelect('users.id as user_id');
        }

        if (!$this->hasExpressions()) {
            return $builder->whereRaw('1 = 0');
        }

        if (!is_null($this->id) || $this->expressions->isNotEmpty()) {
            $this->buildExpressionSql($builder, $admin_user);
        }

        if (!is_null($this->id) || $this->subRules->isNotEmpty()) {
            foreach ($this->subRules as $subRule) {
                $builder->where(function ($query) use ($subRule, $admin_user) {
                    return $subRule->buildRuleSql($query, $admin_user);
                }, null, null, $this->getLogicalOperator());
            }
        }

        return $builder;
    }

    protected function buildExpressionSql($builder, $admin_user)
    {
        $sorted_expressions = $this->sortExpressions($admin_user);

        foreach ($sorted_expressions as $type => $expression_data) {
            $function = "add{$type}Subqueries";
            $this->$function($builder, $expression_data);
        }
    }

    protected function sortExpressions($admin_user)
    {
        $sorted_expressions = [];
        foreach ($this->expressions as $expression) {
            switch ($expression->operand_type) {
                case 'user_attribute':
                    $sorted_expressions["User" . Str::studly($expression->operand)][$expression->conditional_operator][] = $expression->value;
                    break;
                case 'select':
                case 'linked':
                    if ($expression->value == Expression::ADMIN_VALUE) {
                        $this->storeAdminValuesInSortedExpressions($sorted_expressions, 'AdminValue', $expression, $admin_user);
                        break;
                    }

                    $sorted_expressions['FieldValue'][$expression->conditional_operator][] = $expression->value;
                    break;
                case HierarchyField::$type_code:
                    if ($expression->value == Expression::ADMIN_VALUE) {
                        $this->storeAdminValuesInSortedExpressions($sorted_expressions, 'HierarchyAdminValue', $expression, $admin_user);
                        break;
                    }

                    $sorted_expressions['HierarchyFieldValue'][$expression->conditional_operator][] = $expression->value;
                    break;
                case 'date':
                    $sorted_expressions['Date'][$expression->operand][$expression->conditional_operator][] = $expression->value;
                    break;
                case 'group':
                    $sorted_expressions['Group'][$expression->conditional_operator][] = $expression->value;
                    break;
                case 'class':
                    $sorted_expressions['Class'][$expression->conditional_operator][] = $expression->value;
                    break;
                case 'certification':
                    $sorted_expressions['Certification'][$expression->conditional_operator][] = $expression->value;
                    break;
                case UserToUserField::$type_code:
                    if ($expression->value == Expression::ADMIN_VALUE) {
                        $expression->value = $admin_user ? $admin_user->id : Auth::user()->id;
                    }
                    $sorted_expressions['UserToUser'][$expression->operand][$expression->conditional_operator][] = $expression->value;
                    break;
                case 'ability':
                    $sorted_expressions['Ability'][$expression->conditional_operator][] = $expression->value;
                    break;
                default:
                    break;
            }
        }
        return $sorted_expressions;
    }

    private function storeAdminValuesInSortedExpressions(&$sorted_expressions, $adminValueType, $expression, $admin_user)
    {
        /**
         *  Admin Values are grouped by:
         *    1. type (basically, field type) - "AdminValue" (a Select admin value) or "HierarchyAdminValue"
         *    2. field_id ($expression->operand)
         *    3. conditional operator - "is" or "excludes"
         **/
        $admin_values = $this->getAdminValues($expression->operand, $admin_user);
        $this->prepareSortedExpressionsForAdminValuesIfNecessary($sorted_expressions, $adminValueType, $expression);
        foreach ($admin_values as $admin_value) {
            $sorted_expressions[$adminValueType][$expression->operand][$expression->conditional_operator][] = $admin_value;
        }
    }

    private function prepareSortedExpressionsForAdminValuesIfNecessary(&$sorted_expressions, $adminValueType, $expression)
    {
        if (!isset($sorted_expressions[$adminValueType][$expression->operand])) {
            $sorted_expressions[$adminValueType][$expression->operand] = [];
        }
        if (!isset($sorted_expressions[$adminValueType][$expression->operand][$expression->conditional_operator])) {
            $sorted_expressions[$adminValueType][$expression->operand][$expression->conditional_operator] = [];
        }
    }

    protected function getAdminValues($operand, $admin)
    {
        $admin = $admin ?? Auth::user();
        $field = Fields::findOrFail($operand);
        return $admin->profile(false)->get($field)->pluck('id')->all();
    }

    protected function addUserIdSubqueries($builder, $expression_data)
    {
        $logical_operator = $this->getLogicalOperator();
        foreach ($expression_data as $conditional_operator => $values) {
            if ($this->allExpressionsMustBeTrue($conditional_operator, $logical_operator)) {
                $conditional_operator_symbol = ConditionalOperator::getOperator($conditional_operator)['symbol'];
                foreach ($values as $value) {
                    $builder->where("users.id", $conditional_operator_symbol, $value, $logical_operator);
                }
            } else {
                $not = $conditional_operator === ConditionalOperator::EXCLUDES;
                $builder->whereIn("users.id", $values, $logical_operator, $not);
            }
        }
    }

    protected function addUserAttributeSubqueries($builder, $expression_data, $attribute)
    {
        $logical_operator = $this->getLogicalOperator();
        foreach ($expression_data as $conditional_operator => $values) {
            $conditional_operator_symbol = ConditionalOperator::getOperator($conditional_operator)['symbol'];
            foreach ($values as $value) {
                $builder->where("users.$attribute", $conditional_operator_symbol, $value, $logical_operator);
            }
        }
    }

    protected function addUserPointsSubqueries($builder, $expression_data)
    {
        $this->addUserAttributeSubqueries($builder, $expression_data, 'points');
    }

    protected function addUserPreferredLocaleSubqueries($builder, $expression_data)
    {
        $this->addUserAttributeSubqueries($builder, $expression_data, 'preferred_locale');
    }

    protected function addFieldValueSubqueries($builder, $expression_data)
    {
        $logical_operator = $this->getLogicalOperator();
        foreach ($expression_data as $conditional_operator => $values) {
            $values = array_unique($values);
            $subquery = DB::table('profile_values')->select('profile_values.user_id')
                ->whereIn("profile_values.value_id", $values);

            $count = count($values);
            if ($count > 1 && $this->allExpressionsMustBeTrue($conditional_operator, $logical_operator)) {
                $subquery->groupBy('profile_values.user_id')
                    ->havingRaw("COUNT(DISTINCT profile_values.value_id) = {$count}");
            }

            $not = $conditional_operator === ConditionalOperator::EXCLUDES;
            $builder->whereIn('users.id', $subquery, $logical_operator, $not);
        }
    }

    protected function addHierarchyFieldValueSubqueries($builder, $expression_data)
    {
        $logical_operator = $this->getLogicalOperator();
        foreach ($expression_data as $values) {
            $this->addHierarchyFieldValueSubqueriesHelper($builder, $values, $logical_operator);
        }
    }

    private function addAdminValueSubquery($builder, $logical_operator, $conditional_operator, $values)
    {
        if (empty($values)) {
            $subquery = DB::table('users')->select('users.id')->whereRaw('1 = 0');
        } else {
            $subquery = DB::table('profile_values')->select('profile_values.user_id')
                    ->whereIn("profile_values.value_id", $values);
        }

        $not = $conditional_operator === ConditionalOperator::EXCLUDES;
        $builder->whereIn('users.id', $subquery, $logical_operator, $not);
    }

    protected function addAdminValueSubqueries($builder, $expression_data)
    {
        $logical_operator = $this->getLogicalOperator();
        if ($logical_operator == 'AND') {
            /**
             * this group of expressions is like:
             * FieldA is Admin's Value
             *  -AND-
             * FieldB is Admin's Value
             *  -AND-  etc...
             *
             * We will make sure the user has at least one of the Admin's values
             * for each field. The Admin must have at least one value for each field
             * in order for matches to occur with users.
             */
            foreach ($expression_data as $conditional_operator_and_values) {
                foreach ($conditional_operator_and_values as $conditional_operator => $values) {
                    $this->addAdminValueSubquery($builder, $logical_operator, $conditional_operator, $values);
                }
            }
        } else {
            // re-group the values by their conditional_operator for the "OR" case to work properly
            $expression_values_grouped_by_conditional_operator = $this->groupAdminValuesForExpressionByConditionalOperator($expression_data);
            foreach ($expression_values_grouped_by_conditional_operator as $conditional_operator => $values) {
                $this->addAdminValueSubquery($builder, $logical_operator, $conditional_operator, $values);
            }
        }
    }

    private function groupAdminValuesForExpressionByConditionalOperator($expression_data)
    {
        $expression_values_grouped_by_conditional_operator = [];
        foreach ($expression_data as $conditional_operator_and_values) {
            foreach ($conditional_operator_and_values as $conditional_operator => $values) {
                if (!isset($expression_values_grouped_by_conditional_operator[$conditional_operator])) {
                    $expression_values_grouped_by_conditional_operator[$conditional_operator] = [];
                }
                foreach ($values as $value) {
                    $expression_values_grouped_by_conditional_operator[$conditional_operator][] = $value;
                }
            }
        }
        return $expression_values_grouped_by_conditional_operator;
    }

    protected function addHierarchyAdminValueSubqueries($builder, $expression_data)
    {
        $logical_operator = 'AND';
        foreach ($expression_data as $conditional_operator_and_values) {
            foreach ($conditional_operator_and_values as $values) {
                if (empty($values)) {
                    // admin has no values, so add a WHERE clause which evaluates to false
                    $subquery = DB::table('users')->select('users.id')->whereRaw('1 = 0');
                    $builder->whereIn('users.id', $subquery, $logical_operator);
                } else {
                    $this->addHierarchyFieldValueSubqueriesHelper($builder, $values, $logical_operator);
                }
            }
        }
    }

    protected function addDateSubqueries($builder, $expression_data)
    {
        $logical_operator = $this->getLogicalOperator();
        foreach ($expression_data as $operand => $conditional_operators) {
            $subquery = DB::table('profile_values')->select('profile_values.user_id')
                ->join('field_values', function ($join) {
                    $join->on('field_values.id', 'profile_values.value_id');
                })
                ->where('field_values.field_id', $operand)
                ->whereNull('field_values.deleted_at')
                ->where(function ($query) use ($conditional_operators, $logical_operator) {
                    foreach ($conditional_operators as $conditional_operator => $values) {
                        $conditional_operator_symbol = ConditionalOperator::getOperator($conditional_operator)['symbol'];
                        foreach ($values as $value) {
                            $query->where('field_values.value', $conditional_operator_symbol, date("Y-m-d", strtotime($value)), $logical_operator);
                        }
                    }
                });

            $builder->whereIn('users.id', $subquery, $logical_operator);
        }
    }

    protected function addGroupSubqueries($builder, $expression_data)
    {
        $logical_operator = $this->getLogicalOperator();
        foreach ($expression_data as $conditional_operator => $values) {
            $values = array_unique($values);
            $subquery = DB::table('user_group_users')->select('user_group_users.user_id')
                ->whereIn('user_group_users.user_group_id', $values);

            $count = count($values);
            if ($count > 1 && $this->allExpressionsMustBeTrue($conditional_operator, $logical_operator)) {
                $subquery->groupBy('user_group_users.user_id')
                    ->havingRaw("COUNT(DISTINCT user_group_users.user_group_id) = {$count}");
            }

            $not = $conditional_operator === ConditionalOperator::EXCLUDES;
            $builder->whereIn('users.id', $subquery, $logical_operator, $not);
        }
    }

    protected function addCertificationSubqueries($builder, $expression_data)
    {
        $logical_operator = $this->getLogicalOperator();
  
        foreach ($expression_data as $conditional_operator => $certificate_ids) {
            $subquery = DB::table('user_certifications')
                ->select('user_certifications.user_id')
                ->where('user_certifications.status', AwardedCertification::STATUS_AWARDED)
                ->whereIn('user_certifications.certification_id', $certificate_ids);

            $count = count($certificate_ids);
            if ($count > 1 && $this->allExpressionsMustBeTrue($conditional_operator, $logical_operator)) {
                $subquery->groupBy('user_certifications.user_id')
                    ->havingRaw("COUNT(DISTINCT user_certifications.certification_id) = {$count}");
            }

            $not = $conditional_operator === ConditionalOperator::EXCLUDES;
            $builder->whereIn('users.id', $subquery, $logical_operator, $not);
        }
    }

    protected function addAbilitySubqueries($builder, $expression_data)
    {
        /*
            Expressions Data has ability ID, we want the roles associated with it.
            1. Go through role_abilities, grab roles
        */
        $logical_operator = $this->getLogicalOperator();
        foreach ($expression_data as $conditional_operator => $values) {
            $values = array_unique($values);
            $roles = Role::where(function ($query) use ($values) {
                $query->whereHas('abilities', function ($sub) use ($values) {
                    $sub->whereIn('abilities.id', $values);
                })->orWhere('role_type', SuperAdminRole::class);
            })->get();

            /*
                2. Using laravel relations, we get the rules associated with the roles obtained
                e.g. $role->adminRule();
                3. Once we obtain rules, we add the associated expressions to the subquery (buildRuleSql())
            */

            $roleAdminRuleSubqueries = [];

            if ($roles->isNotEmpty()) {
                foreach ($roles as $role) {
                    $rule = $role->adminRule->first();
                    if (!empty($rule)) {
                        $ruleSubquery = $rule->buildRuleSql();
                        $roleAdminRuleSubqueries[] = strstr(get_query_string_with_bindings($ruleSubquery), "from", false);
                    }
                }
    
                if (count($roleAdminRuleSubqueries) > 0) {
                    $builder->where(function ($query) use ($roleAdminRuleSubqueries) {
                        foreach ($roleAdminRuleSubqueries as $index => $roleAdminRuleSubquery) {
                            if ($index === 0) {
                                $query->whereIn("users.id", function ($sub) use ($roleAdminRuleSubquery) {
                                    $sub->selectRaw("users.id {$roleAdminRuleSubquery}");
                                });
                            } else {
                                $query->orWhereIn("users.id", function ($sub) use ($roleAdminRuleSubquery) {
                                    $sub->selectRaw("users.id {$roleAdminRuleSubquery}");
                                });
                            }
                        }
                    });
                } else {
                    // If no role admins, we should not grab anyone
                    $builder->whereRaw('1 = 0');
                }
            } else {
                // If no roles possess ability, we should not grab anyone
                $builder->whereRaw('1 = 0');
            }
        }
    }

    protected function addClassSubqueries($builder, $expression_data)
    {
        if (!Classes::exists()) { //if classes are disabled, don't add class expressions
            return $builder;
        }

        $logical_operator = $this->getLogicalOperator();
        foreach ($expression_data as $conditional_operator => $values) {
            $values = array_unique($values);
            $not = $conditional_operator === ConditionalOperator::EXCLUDES;
            if (in_array('none', $values)) { //no class
                $subquery = DB::table('user_group_users')->select('user_group_users.user_id')
                    ->join('class_user_groups', 'class_user_groups.user_group_id', 'user_group_users.user_group_id');

                $builder->whereIn('users.id', $subquery, $logical_operator, !$not);

                $values = array_diff($values, ['none']);
                if (count($values) < 1) {
                    return;
                }
            }

            $subquery = DB::table('user_group_users')->select('user_group_users.user_id')
                ->join('class_user_groups', 'class_user_groups.user_group_id', 'user_group_users.user_group_id')
                ->whereIn('class_user_groups.class_id', $values);

            $count = count($values);
            if ($count > 1 && $this->allExpressionsMustBeTrue($conditional_operator, $logical_operator)) {
                $subquery->groupBy('user_group_users.user_id')
                    ->havingRaw("COUNT(DISTINCT class_user_groups.class_id) = {$count}");
            }

            $builder->whereIn('users.id', $subquery, $logical_operator, $not);
        }
    }

    protected function allExpressionsMustBeTrue($conditional_operator, $logical_operator)
    {
        $not = $conditional_operator === ConditionalOperator::EXCLUDES;
        return (!$not && $logical_operator == 'AND') || ($not && $logical_operator == 'OR');
    }

    protected function addHierarchyFieldValueSubqueriesHelper($builder, $values, $logical_operator)
    {
        $values = array_unique($values);
        foreach ($values as $value) {
            $subquery = DB::table('profile_values')->select('profile_values.user_id')
                ->whereIn("profile_values.value_id", function (Builder $query) use ($value) {
                    $query->select(['field_value_id'])
                        ->from('hierarchy_nodes')
                        ->whereRaw("hierarchy_nodes.path LIKE "
                            ."CONCAT((select path from hierarchy_nodes WHERE field_value_id = ?), '%')", [$value]);
                });

            $builder->whereIn('users.id', $subquery, $logical_operator);
        }
    }

    protected function addUserToUserSubqueries($builder, $expression_data)
    {
        $logical_operator = $this->getLogicalOperator();
        foreach ($expression_data as $field_id => $conditional_operator_and_values) {
            foreach ($conditional_operator_and_values as $conditional_operator => $values) {
                switch ($conditional_operator) {
                    case ConditionalOperator::IS:
                        $subquery = DB::table('profile_values')->select('profile_values.user_id')
                            ->whereIn("profile_values.related_user_id", $values);
                        $builder->whereIn('users.id', $subquery, $logical_operator);
                        break;
                    case ConditionalOperator::IS_EQUAL_TO_OR_BELOW_THE_ADMINISTRATOR:
                    case ConditionalOperator::IS_EQUAL_TO_OR_BELOW:
                        $this->addUserToUserSubqueriesHelper($builder, $field_id, $values, $logical_operator);
                        break;
                }
            }
        }
    }

    private function addUserToUserSubqueriesHelper($builder, $field_id, $related_user_ids, $logical_operator)
    {
        $user_to_user_nested_depth_limit = client_setting('user_to_user_nested_depth_limit');
        $levelQueriesToBeUnioned = new Collection();
        $level1_subquery = DB::table('profile_values')->select('profile_values.user_id')
            ->where('field_id', $field_id)
            ->whereIn("profile_values.related_user_id", $related_user_ids);

        $previous_level_subquery = get_query_string_with_bindings($level1_subquery); //L1
        $levelQueriesToBeUnioned->push($previous_level_subquery);
        // now do this nested query building "$user_to_user_nested_depth_limit - 1" times:
        for ($level = 1; $level < $user_to_user_nested_depth_limit; $level++) {
            //L2 = SELECT user_id from profile_values where related_user_id in ( L1 )
            $this_level_subquery = "SELECT user_id FROM profile_values " .
                "WHERE related_user_id IN ($previous_level_subquery) " .
                " AND field_id = $field_id";
            $levelQueriesToBeUnioned->push($this_level_subquery);
            $previous_level_subquery = $this_level_subquery;
        }

        $unionedQuery = $levelQueriesToBeUnioned->join(' UNION ALL ');
        // now add the huge, fugly query to the builder
        $builder->whereRaw("users.id IN ($unionedQuery)", [], $logical_operator);
    }

    /**
     * Lookup operator label by id
     * @return mixed
     */
    public function getLogicalOperator()
    {
        return LogicalOperator::getOperator($this->logical_operator)['label'];
    }


    /**
     * Create a new rule
     * @param array $rule_data
     * @param bool $storeRevision
     * @return mixed
     */
    public static function createRule(array $rule_data, $storeRevision = true)
    {
        $rule_attributes = array_intersect_key($rule_data, array_flip(['parent_rule_id', 'logical_operator', 'client_id', 'name']));
        $rule = Rule::create($rule_attributes);
        $rule->saveExpressionsAndSubRules($rule_data);

        if ($storeRevision) {
            $rule->storeRevision("Created");
        }

        return $rule;
    }

    /**
     * Update an existing rule
     * @param array $rule_data
     * @param Rule $rule
     */
    public static function updateRule(array $rule_data, Rule $rule)
    {
        $rule->update($rule_data);

        // delete old expressions and sub-rules
        $rule->deleteExpressionsAndSubRules();

        //save new expressions and sub-rules
        $rule->saveExpressionsAndSubRules($rule_data);

        $rule->storeRevision("Updated");
    }

    /**
     * Copies a rule with all it's expressions and sub-rules and saves it to the database
     * @return Rule
     * @throws Exception
     */
    public function copyRule(): Rule
    {
        $new_rule = $this->replicate(['uuid']);

        if (strpos($this->name, "(copy)") !== false) { // found
            $rule_name_without_copy = str_replace('(copy)', '', $this->name);
            $new_rule->name = trim($rule_name_without_copy) . " (copy)";
        } else {
            $new_rule->name = $this->name . " (copy)";
        }

        $new_rule->save();
        $new_rule->saveExpressionsAndSubRules($this->toArray());

        return $new_rule;
    }


    /**
     * Create and save the expressions and subrules recursively
     * @param array $rule_data
     */
    protected function saveExpressionsAndSubRules(array $rule_data)
    {
        if (!empty($rule_data['expressions'])) {
            foreach ($rule_data['expressions'] as $expression_data) {
                $ex = new Expression($expression_data);
                $this->expressions()->save($ex);
            }
        }

        if (!empty($rule_data['sub_rules'])) {
            foreach ($rule_data['sub_rules'] as $sub_rule_data) {
                $sub_rule = Rule::createRule($sub_rule_data, false);
                $this->subRules()->save($sub_rule);
            }
        }
    }


    public function deleteExpressionsAndSubRules()
    {
        $expression_ids = $this->expressions->pluck('id');
        Expression::whereIn('id', $expression_ids)->delete();
        foreach ($this->subRules as $sub_rule) {
            $sub_rule->deleteExpressionsAndSubRules();
            $sub_rule->delete();
        }
    }


    /**
     * Create a rule object with its expressions and sub_rules without saving any to the DB
     * @param array $rule_data
     * @return Rule
     */
    public static function createTransientRule(array $rule_data)
    {
        $rule = new Rule($rule_data);
        $rule->createTransientExpressions($rule_data);

        return $rule;
    }

    /**
     * create expressions and sub_rules for a transient rule
     * @param $rule_data
     */
    protected function createTransientExpressions($rule_data)
    {
        $expressions = new Collection();
        if (isset($rule_data['expressions'])) {
            foreach ($rule_data['expressions'] as $expression_data) {
                $expressions->push(new Expression($expression_data));
            }
        }
        $this->setRelation('expressions', $expressions);

        if (isset($rule_data['sub_rules'])) {
            $sub_rules = new Collection();
            foreach ($rule_data['sub_rules'] as $sub_rule_data) {
                $sub_rules->push(Rule::createTransientRule($sub_rule_data));
            }
            $this->setRelation('subRules', $sub_rules);
        }
    }


    /**
     * Save a simple JSON encoded version of the rule
     * @param string $action
     */
    public function storeRevision($action)
    {
        $key = "Rule " . $action;
        $revision_data = [
            'revisionable_type' => \App\Rule::class,
            'revisionable_id'   => $this->id,
            'key'               => $key,
            //'old_value'           =>
            'new_value'  => json_encode($this->transformRuleForStorage()),
            'user_id'    => Auth::id(),
            'created_at' => new \DateTime(),
            'updated_at' => new \DateTime(),
        ];

        $revision = new Revision();
        DB::table($revision->getTable())->insert($revision_data);
    }

    /**
     * Return a simplified array of the complete rule
     * @return array
     */
    public function transformRuleForStorage()
    {
        $rule = [];
        $rule['o'] = $this->logical_operator;
        foreach ($this->expressions as $expression) {
            $rule['e'][] = $expression->operand_type . "|" . $expression->operand . "|" . $expression->conditional_operator . "|" . $expression->value;
        }
        foreach ($this->subRules as $sub_rule) {
            $rule['r'] = $sub_rule->transformRuleForStorage();
        }

        return $rule;
    }


    /**
     * Combine a collection of rules together, making each into sub_rules joined by the specified logical_operator
     * @param SupportCollection $rules
     * @param $logical_operator
     * @return Rule
     */
    public static function combine(SupportCollection $rules, $logical_operator = 'and')
    {
        $client_id = $rules->first()->client_id ?? 0;
        $combined_rule = new Rule(['client_id' => $client_id]);
        $combined_rule->logical_operator = $logical_operator == 'and' ? LogicalOperator::AND : LogicalOperator::OR;
        $combined_rule->setRelation('subRules', $rules);

        return $combined_rule;
    }

    /**
     * Combines a collection of rules with "AND" joining them, and saves
     * as new rule in DB with relationships set properly
     * @return Rule : the new combined Rule now saved in the DB - would require a refresh() to peruse its new subrules/expressions
     */
    public static function combineAndSave(SupportCollection $rules)
    {
        $newCombinedRule = self::combine($rules);
        $newCombinedRule->save();
        $rules->each(function ($subRule) use ($newCombinedRule) {
            $subRule->parent()->associate($newCombinedRule);
            $subRule->save();
        });

        return $newCombinedRule;
    }

    /**
     * get top most parent of rule
     * @return $this
     */
    public function getParentRule()
    {
        if (empty($this->parent)) {
            return $this;
        }

        $parent_rule = $this->parent;

        return $parent_rule->getParentRule();
    }

    public function containsAdminValueExpression()
    {
        foreach ($this->expressions as $expression) {
            if ($expression->value === Expression::ADMIN_VALUE) {
                return true;
            }
        }

        foreach ($this->subRules as $sub_rule) {
            if ($sub_rule->containsAdminValueExpression()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recurse over rule (and sub-rules) and retain ONLY the expressions that use "Admin's value"
     * This is primarily intended to support the identification of role admins for a given user because it
     * isolates the specific criteria an admin must fufill
     * e.g. if a role audience has Location =  admin value and we have a user with Location = Bozeman
     * we know that the admin for this user must also have Location = Bozeman
     *
     * NB. applies the filter to a COPY and returns the COPY, the original rule should not be affected
     */
    public function cloneAndRetainAdminValueExpression() : Rule
    {
        $clone = Rule::createTransientRule($this->toArray());
        $clone->filterExpressions(function ($e) {
            return ($e->value == Expression::ADMIN_VALUE);
        });
        return $clone;
    }
    /**
     * inverse of above
     */
    public function cloneAndRejectAdminValueExpression() : Rule
    {
        $clone = Rule::createTransientRule($this->toArray());
        $clone->filterExpressions(function ($e) {
            return ($e->value != Expression::ADMIN_VALUE);
        });
        return $clone;
    }
    /**
     * Potentially useful utility to apply a filter callback to the expressions in this and all sub rules
     */
    public function filterExpressions($filterCallback)
    {
        $this->setRelation('expressions', $this->expressions->filter($filterCallback));
        foreach ($this->subRules as $sub_rule) {
            $sub_rule->filterExpressions($filterCallback);
        }
        //prune out any empty subrules
        $this->setRelation('subRules', $this->subRules->filter(function ($r) {
            return $r->expressions->isEmpty();
        }));
    }



    public function containsUserToUserExpression()
    {
        foreach ($this->expressions as $expression) {
            if ($expression->operand_type === UserToUserField::$type_code) {
                return true;
            }
        }

        foreach ($this->subRules as $sub_rule) {
            if ($sub_rule->containsUserToUserExpression()) {
                return true;
            }
        }

        return false;
    }
}
