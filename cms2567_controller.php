<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use DateTime;
use App\Providers\Storage\TMPData;
use App\Models\TaskDetail;
use App\Models\TaskTracking;
use mPDF;
use DB;
use UserLog;
use App\Jobs\RemoveTMPData;
use GrahamCampbell\Flysystem\Facades\Flysystem;
use \App\Providers\Core\Facility\Deficiency\Deficiency;
use \App\Providers\Core\Common\DBFunctions;
use Config;
use SafeFile;

use \Facility;

//----------------------------------------------------------------------------------------------------------------------


class CMS2567ControllerNew extends Controller
{

//----------------------------------------------------------------------------------------------------------------------

    protected $start;
    protected $end;

    protected $is_ss;
    protected $print_mode;


    // dump debug info instead of the preview
    protected $dump = 0;

    // used in Single Task mode (specific task only)
    protected $td_id;

    // used in Single User mode
    protected $user_id;

    // which mode are we in? (all, user, task)
    protected $mode;

    // show extra debug info in the view (dev only)
    protected $debug = 0;

    // should samples start as visible or hidden?
    protected $show_samples = 1;    


//----------------------------------------------------------------------------------------------------------------------
// show list of task details only with details we need

    function debugTDs($tds)
    {
        $rows = [];
        if ($tds) foreach ($tds as $td) {

             $history = [];
             foreach ($td->history as $h) {
                 $history[] = [
                    'yes'              => $h['yes'],
                    'no'               => $h['no'],
                    'samples'          => $h['samples'],
                    'no_samples'       => $h['no_samples'],
                    'comments'         => $h['comments'],
                    'last_usr_updated' => $h['last_usr_updated'],
                    'user'             => $h['user'],
                    'updated_at'       => $h['updated_at'],
                    //'user_comments'    => $h->user_comments,
                 ];
             }

             $rows[] = [
                 'ind'         => '['.indID($td->task->ind_uid).'] - '.$td->task->name.' (#'.$td->task->ind_uid.')' ,
                 'task_uid'    => $td->task_uid,
                 'tag'         => $td->tag.' / '.$td->poc,
                 'reg'         => $td->reg,
                 'user'        => $td->user_uid,
                 'entries'     => $td->entries,
                 'samples'     => $td->samples,
                 'history'     => $history,
             ];
        }

       // dd($tds);

        dp($rows);
    }


//----------------------------------------------------------------------------------------------------------------------
// update history entry with additional details (name, changes etc.)


function updateHistory(&$td, $history)
    {
        $useHistoryDateCompleted = false;

        if($this->start && $this->end)
        {
            if(strtotime($td->created_at) < strtotime($this->start))
            {
                $td->completed = date('m/d/Y', strtotime($this->start));
                $useHistoryDateCompleted = true;
            }
            else if(strtotime($td->updated_at) > strtotime($this->end))
            {
                $td->completed = date('m/d/Y', strtotime($this->end));
                $useHistoryDateCompleted = true;
            }
        }
        // add data from current TD to the history
        $history[] = [
            'yes'               => $td->yes,
            'no'                => $td->no,
            'samples'           => $td->samples,
            'comments'          => $td->comments,
            'last_usr_updated'  => $td->last_usr_updated,
            'updated_at'        => $td->updated_at,
        ];
        // apply additional fields (user info etc.)
        if ($history)
        {
            foreach ($history as $i => $h) {
                if($h['updated_at'] >= strtotime(date('Y-m-d 23:59:59', strtotime($this->end))) || strtotime($h['updated_at']) <= strtotime($this->start)) continue;
                if($useHistoryDateCompleted && strtotime($h['updated_at']) >= strtotime($this->start) && strtotime($h['updated_at']) <= strtotime(date('Y-m-d 23:59:59', strtotime($this->end)))) $td->completed = date('m/d/Y', strtotime($h['updated_at']));
                if(strtotime($h['updated_at']) >= strtotime(date('Y-m-d 23:59:59', strtotime($this->end)))) $completed = date('m/d/Y', strtotime($h['updated_at']));
                $h['no']      = json_decode($h['no']);
                $h['yes']     = json_decode($h['yes']);
                $h['samples'] = json_decode($h['samples']);
                if ($h['no']) foreach ($h['no'] as $sample_index) {
                    $history[$i]['no_samples'][] = $h['samples'][$sample_index-1];
                }
                if($i !== 0)
                {
                    if($history[$i-1]['no'] == $h['no'] && $history[$i-1]['yes'] == $h['yes'] && $history[$i-1]['samples'] == $h['samples']) continue;
                }
                // save decoded data back
                $history[$i]['no']      = $h['no'];
                $history[$i]['yes']     = $h['yes'];
                $history[$i]['samples'] = $h['samples'];
                $u = \User::find($h['last_usr_updated']);
                $history[$i]['user'] = $u->first_name.' '.$u->last_name;
            }
        }
        return $history;
    }



//----------------------------------------------------------------------------------------------------------------------
// open previously printed PDF


    public function pdf(Request $request)
    {
        $hash = $request->file;

        SafeFile::getFileFromHash($request->file, 'CMS2567');
        die();
    }


//----------------------------------------------------------------------------------------------------------------------


    public function footer(Request $request)
    {
        $admin_name = base64_decode($request->admin_name);
        $fac_name   = base64_decode($request->fac_name);

        $date = date('m/d/Y');

        $footer = <<<HEREDOC
            <!DOCTYPE HTML>
            <html>
                <head>
                    <meta charset="utf-8">
                    <script>
                        function init()
                        {

                        }
                    </script>
                </head>

                <body onload="init()">
                    <div style='background:white; padding:7px; margin-top:-1.8%;'>
                        <table border='1' style='margin-bottom:0px; margin-left:-1.5%; padding: 7px; width:103%; border-collapse:collapse; background:white; border-right:0; border-left:0;'>
                            <tr>
                                <td colspan='3' style='font-size:10px !important; padding:4px 2px 4px 2px; border-left:0; border-right:0'>
                                Any deficiency statement ending with an asterisk (*) denotes a deficiency which the institution may be excused from correcting providing it is determined that other safeguards provide sufficient protection to the patients. (See reverse for further instructions.) Except for nursing homes, the findings stated above are disclosable 90 days following the date of survey whether or not a plan of correction is provided. For nursing homes, the above findings and plans of correction are disclosable 14 days following the date these documents are made available to the facility. If deficiencies are cited, an approved plan of correction is requisite to continued program participation
                                </td>
                            </tr>
                            <tr>
                                <td width='55%' style='font-size:8.5px; border-left:0; border-right:0; padding:8px !important;'>
                                     LABORATORY DIRECTOR'S OR PROVIDER/SUPPLIER REPRESENTATIVE'S SIGNATURE<br>
                                     <span style='font-size:18px;'>$admin_name</span>
                                </td>
                                <td style='font-size:9px; padding:8px !important;' width='20%'>
                                    TITLE<br>
                                    <span style='font-size:18px;'>Administrator</span>
                                </td>
                                <td style='font-size:9px; border-left:0; border-right:0; padding:8px !important;' width='25%'>
                                    (X6) DATE<br>
                                    <span style='font-size:18px;'>$date</span>
                                </td>
                            </tr>
                        </table>
                        <table style='width:103%; margin-left:-1.5%;'>
                            <tr>
                                <td style='border-left:0; border-right:0; border-bottom:0; font-size:14px !important; padding-right: 10px !important;' width='33%'>
                                    FORM CMS-2567(02/99) Previous Versions Obsolete
                                </td>
                                <td style='border-left:0; border-right:0; border-bottom:0; text-align:center; font-size:14px !important;' width='33%'><bold>{$fac_name}</bold></td>
                                <td colspan='2' style='border-left:0; border-right:0; float:right; border-bottom:0; text-align:right; font-size:14px !important;' width='33%'>
                                    Page {$request->page} of {$request->topage}
                                </td>
                            </tr>
                        </table>
                        <br><br>
                    </div>
                </body>
            </html>
HEREDOC;

        echo $footer;
    }


//----------------------------------------------------------------------------------------------------------------------
// print background image that later is put into original pdf



    function printBg(Request $request)
    {
        //$bg = '/var/www/snfqapi/site/www/assets/images/cms-pdf-vert-bg-marked.png';
        $bg = '/var/www/snfqapi/site/www/assets/images/cms-pdf-vert-bg.png';

        $html="
        <html>
        <head>
        <style>
            body { padding: 0; margin 0; }
        </style>
        </head>
        <body>
            <img src='$bg' style='width:2700px; height:2238px'>
        </body>
        </html>";


        // save as html, run wkhtmltopdf
        $file      = '/dev/shm/bg';
        $file_html = $file.'.html';
        $file_pdf  = $file.'.pdf';

        $output=''; $ret = null;
        file_put_contents($file_html, $html);

        exec("/opt/wkhtmltox/bin/wkhtmltopdf \
             --header-right 'FORMxP81' \
              -T 0 -B 0 -L 0 -R 0 \
              --dpi 150 \
             $file_html $file_pdf", $output, $ret);

        if ($request->isMethod('get'))
        {

            header('Content-type: application/pdf');
            header('Content-Disposition: inline; filename="cms2567.pdf"');
            header('Content-Transfer-Encoding: binary');
            header('Accept-Ranges: bytes');
            @readfile($file_pdf);
            return;

        }



        #####################################################



        $bg = '/var/www/snfqapi/site/www/assets/images/cms-pdf-vert-bg.png';
        //$bg = 'https://snfqapi.com/assets/images/cms-pdf-vert-bg.png';

        $html="
        <html>
        <head>
        <style>
            body { padding: 0; margin 0; }
        </style>
        </head>
        <body>
            <img src='$bg' style='width:8.26 in; height:11.69 in'>
        </body>
        </html>";


        // save as html, run wkhtmltopdf
        $file      = '/dev/shm/cms2567-'.time();
        $file_html = $file.'.html';
        $file_pdf  = $file.'.pdf';

        $output=''; $ret = null;
        file_put_contents($file_html, $html);

        exec("/opt/wkhtmltox/bin/wkhtmltopdf \
             -T 0 -B 0 -L 0 -R 0 \
             $file_html $file_pdf", $output, $ret);


        // save to file instead
        $sf = SafeFile::newInstance();
        $sf->returnURL = true;
        $file = 'pdf/CMS2567/'.time().'_cms2567.pdf';
        $sf->setFacPath();

        // recreate directory if it doesnt exist yet
        $folder = $sf->savePath().'pdf/CMS2567/';
        if (!file_exists($folder))
            @mkdir($folder);

        $savePath = $sf->savePath().$file;
        file_put_contents($savePath, fopen($file_pdf, 'r'));
        $sf->metaKey = 'CMS2567';
        $sf->meta = encrypt(['data' => $savedData]);
        $sf->fileAlreadySaved($file, 'CMS2567');

        if ($request->isMethod('get'))
        {

            header('Content-type: application/pdf');
            header('Content-Disposition: inline; filename="cms2567.pdf"');
            header('Content-Transfer-Encoding: binary');
            header('Accept-Ranges: bytes');
            echo @readfile($file_pdf);
            return;

        }

        return $sf->hash;
    }



//----------------------------------------------------------------------------------------------------------------------
// print PDF


    public function printPdf (Request $request)
    {
        // https://snfqapi.com/facility/CMS2567/print?is_ss=1&print_mode=1&mode=all&start=2017-06-01&end=2017-06-30&bg=1
        if ($request->bg==1)
        {
            return $this->printBg($request);
            return;
        }

        $this->dump       = 0;
        $this->is_ss      = $request->is_ss;
        $this->print_mode = 1;

        $html = $this->process($request, $request->mode);


        // find facility and admin info
        $fac_uid = session('fac_uid');
        $facility = \Facility::find($fac_uid);
        $fac_name = $facility->name;

        $admin = \User::find($facility->user);
        $admin_name = $admin->first_name.' '.$admin->last_name;

        $fac64 = base64_encode($fac_name);
        $admin64 = base64_encode($admin_name);

        // save as html, run wkhtmltopdf
        $file      = '/dev/shm/cms2567-'.time();
        $file_html = $file.'.html';
        $file_pdf  = $file.'.pdf';

        $output=''; $ret = null;
        file_put_contents($file_html, $html);

        // pass admin name facility name to the footer
        //$footer_url = 'https://snfqapi.com/cms-footer?admin_name='.$admin64.'&fac_name='.$fac64;
        $footer_url = 'https://snfqapi.com/cms-footer/'.$fac64.'/'.$admin64.'?nodbar=1';


        // margin-top: 18mm
        // --header-spacing 3.5 \

             // --header-font-size 8 \
             // --header-spacing 5.0 \


        exec("/opt/wkhtmltox/bin/wkhtmltopdf \
             --margin-bottom 40mm \
             --footer-html $footer_url \
             --header-left 'DEPARTMENT OF HEALTH AND HUMAN SERVICES\nCENTERS FOR MEDICARE & MEDICAID SERVICES' \
             --header-right 'FORM APPROVED\nOMB NO. 0838-0391' \
             --header-font-size 8 \
             --header-spacing 6.0 \
             --margin-top 18mm \
             $file_html $file_pdf", $output, $ret);


        // save to file instead
        $sf = SafeFile::newInstance();
        $sf->returnURL = true;
        $file = 'pdf/CMS2567/'.time().'_cms2567.pdf';
        $sf->setFacPath();

        // recreate directory if it doesnt exist yet
        $folder = $sf->savePath().'pdf/CMS2567/';
        if (!file_exists($folder))
            @mkdir($folder);


        $savePath = $sf->savePath().$file;
        //$mpdf->Output($savePath, 'F');
        file_put_contents($savePath, fopen($file_pdf, 'r'));
        $sf->metaKey = 'CMS2567';
        $sf->meta = encrypt(['data' => $savedData]);
        $sf->fileAlreadySaved($file, 'CMS2567');


        // if used in GET mode (for debugging), dump it on the screen instead!
        // https://snfqapi.com/facility/CMS2567/print?is_ss=1&print_mode=1&mode=all&start=2017-06-01&end=2017-06-30
        //
        if ($request->isMethod('get'))
        {

            header('Content-type: application/pdf');
            header('Content-Disposition: inline; filename="cms2567.pdf"');
            header('Content-Transfer-Encoding: binary');
            header('Accept-Ranges: bytes');
            @readfile($file_pdf);
            return;

        }

        return $sf->hash;
    }


//----------------------------------------------------------------------------------------------------------------------
// get images for the TD


    function getImageMeta($td_uid, $is_survey)
    {
        $imageMeta = \Meta::where('key', 'indicator.'.$td_uid)->get(['uid', 'data']);
        $td_images = [];
        if($imageMeta->isEmpty()) return $td_images;
        foreach($imageMeta as $meta)
        {
            $fullSize = SafeFile::getImagePathFromID($meta->file[0]->uid);
            $meta = @json_decode($meta->data, true);
            if(!$meta) continue;
            $cropped = SafeFile::getImagePathFromID($meta['cropped']);
            unset($meta['cropped']);
            $meta['image'] = $fullSize;
            $meta['cropped'] = $cropped;
            if(!empty($meta['image']) && !empty($meta['cropped'])) $td_images[] = $meta;
        }
        $td_images = collect($td_images)->groupBy('sample')->toArray();

        //dd($td_images);

        if(true)//$this->print_mode)
        {
            // We need to get the hash
            if($td_images) foreach($td_images as $sample => $sampleImages){
                if($sampleImages) foreach($sampleImages as $imageMeta){
                    preg_match('/[^\/]+$/', $imageMeta['image'], $hash);
                    $image = \FacilityFiles::where('hash', $hash)->first();
                    $type = pathinfo($image->path, PATHINFO_EXTENSION);
                    $imageMeta['image'] = 'data:image/' . $type . ';base64,'.base64_encode(file_get_contents(storage_path().'/facility/'.session('fac_uid').'/'.$image->path));
                    $printImages[] = $imageMeta;
                }
            }
            //dd($printImages);
            return $printImages;
        }
        else
        {
            return $td_images;
        }

        return false;
    }


//----------------------------------------------------------------------------------------------------------------------
// main query to show the list


    function list()
    {
        $is_ss    = $this->is_ss;
        $td_id    = $this->td_id;    // used for /task (single-task mode)
        $user_id  = $this->user_id; // used for /user (single-user mode)

        // reset filters to default
        $anytime           = 0;
        $single_task_query = "1";
        $single_user_query = "1";

        // get basics
        $db = $db = DBFunctions::rootDB();
        $fac_uid = session('fac_uid');
        $facdb = 'facility_'.$fac_uid;
        $facility = \Facility::find($fac_uid);
	    $adminUser = \User::find($facility->user);

        // single-task mode
        if ($td_id)
        {
            $td      = \TaskDetail::find($td_id);
            $is_ss   = $td->is_support_services;
            $anytime = 1; // dont use date range
            $single_task_query = "td.uid = $td_id";
        }

        // single-user mode
        if ($user_id)
        {
            $user    = \User::find($user_id);
            $is_ss   = $user->is_surveyor;
            $anytime = 0; // use date range (default)

            // first find all TDs where this user is involved (within provided timeframe)
            $user_tds = $db->select("SELECT DISTINCT td.uid

                                    FROM $facdb.task_details td
                                    JOIN $facdb.task_tracking tt
                                        ON tt.task_detail_uid = td.uid

                                    WHERE
                                    (
                                        tt.is_ss = :is_ss

                                        AND
                                        (
                                            (td.updated_at >= :start AND td.updated_at <= DATE_ADD(:end, INTERVAL 1 DAY))
                                        OR
                                            (td.created_at >= :start AND td.created_at <= DATE_ADD(:end, INTERVAL 1 DAY))
                                        )

                                        AND td.samples != '[]'
                                        AND tt.user_uid = :user_id
                                    )

                                    ORDER BY td.uid
                                    ",
                                    [
                                         ':start'     => $this->start,
                                         ':end'       => $this->end,
                                         ':is_ss'     => $is_ss,
                                         ':user_id'   => $user_id,
                                    ]);

            // collect td.uids
            $user_td_list = [];
            if ($user_tds)
            {
                foreach ($user_tds as $td) {
                    $user_td_list[] = $td->uid;
                }

                $user_td_list = implode(',', $user_td_list);
                $single_task_query = "td.uid IN ($user_td_list)";
            }
            else
            {
                // no results since user was not active in any of the tasks
                $single_task_query = "0";
            }

        }


        $tds = $db->select("SELECT
                            td.*,
                            td.uid as td_uid,
                            tt.uid as tt_uid,
                            tt.user_uid,
                            tt.month,
                            tt.year,
                            tt.day,

                            GROUP_CONCAT(CONCAT('\n\t\t\t[TD:',td.uid,' TT:',tt.uid,' U:',tt.user_uid,' \t@',tt.created_at,'] ') ORDER BY tt.created_at ASC) as entries,

                            CONCAT(u.first_name,' ',u.last_name) as name

                            FROM $facdb.task_details td
                            JOIN $facdb.task_tracking tt
                                ON tt.task_detail_uid = td.uid
                            JOIN snfqapi.users u
                                ON u.uid = tt.user_uid
                            JOIN $facdb.tasks t
                                ON t.uid = td.task_uid
                            JOIN snfqapi.indicators ind
                                ON ind.uid = t.ind_uid

                            WHERE
                            (
                                tt.is_ss = :is_ss

                                AND (
                                        ((td.updated_at >= :start AND td.updated_at <= DATE_ADD(:end, INTERVAL 1 DAY)) OR (td.created_at >= :start AND td.created_at <= DATE_ADD(:end, INTERVAL 1 DAY)))
                                        OR
                                        $anytime
                                    )

                                AND td.samples != '[]'
                                AND $single_task_query
                                AND $single_user_query
                            )

                            GROUP BY td.task_uid, tt.day

                            #ORDER BY td.uid ASC
                            ORDER BY ind.dep_uid ASC, ind.dep_count ASC, td.uid ASC
                            ",
                            [
                                 ':start'     => $this->start,
                                 ':end'       => $this->end,
                                 ':is_ss'     => $is_ss,
                            ]);

        $def = new Deficiency();
        $compliant = true;
        //


        if ($tds) foreach ($tds as $i => &$td)
        {

            // get Task and Regulation
            $task = $db->select("SELECT * FROM $facdb.tasks INNER JOIN snfqapi.indicators AS i ON  i.uid = tasks.ind_uid WHERE tasks.uid = :id", ['id'=>$td->task_uid])[0];
            $reg  = $db->select("SELECT * FROM cms2567.forms WHERE id = :reg_id", ['reg_id'=>$task->reg_uid])[0];

            $td_obj = \TaskDetail::where(['uid'=>$td->uid])->get()->first();
            $poc = $def->getPOC($td_obj);


            //if we have a 'yes' one, skip. if we don't, we aren't compliant


            $td->completed   = date('m/d/Y', strtotime($td->created_at));
            // prepare history
            if ($is_ss)
            {
                $history = [];
                $history = $this->updateHistory($td, json_decode($td->history, true));
            }


            $td->tag = ($task->dep_uid == 8 ? 'AD-'.\CommonFunctions::paddNumber($task->dep_count) : $reg->id_tag);
            $td->reg = $reg->regulation;
            $td->poc = $poc;
            $td->deficiency = $reg->deficiency ? nl2br($reg->deficiency) : '<strong>'.$task->name.'</strong>';
            $td->task = $task;
            $td->task->ind_name = Config::get('constants')['category_abbr'][$task->dep_uid].'-'.\CommonFunctions::paddNumber($task->dep_count);
            $td->history = $history;

            $td->correction  = ($td->poc_override != "") ? nl2br($td->poc_override) : nl2br($reg->correction);
	    if($td->poc_override == "0") $td->correction = "";

            // Get images.
            $td->images      = $this->getImageMeta($td->uid, $td->task->is_survey);

            if($td->no != '[]')
            {
                $compliant = false;
                $tds_filtered[$i]=$td;
            }

        }

        //organize for compliant vs. non-compliant
        if(!$compliant)
        {
            $tds = $tds_filtered;
        }
        else
        {
            $tds = collect($tds)->groupBy('last_usr_updated');
        }

        //dd($tds_filtered);

        if ($this->dump)
            $this->debugTDs($tds);

        $completed = objMax($tds,'updated_at') ?: date('Y-m-d');

        // pass data to the view
        $data['date_completed'] = $td_id ? date('m/d/Y', strtotime($completed)) : date('m/d/Y',strtotime($this->end));
        $data['tds']            = $tds;
        $data['facility']       = $facility;
        $data['compliant']      = $compliant;
        $data['is_ss']          = $is_ss;
        $data['print_mode']     = $this->print_mode;
        $data['start']          = $this->start;
        $data['end']            = $this->end;
        $data['mode']           = $this->mode;
        $data['debug']          = $this->debug;
        $data['show_samples']   = $this->show_samples;
	    $data['admin_user']	    = $adminUser;

        if ($this->mode == 'task')
            $data['td_id']      = $this->td_id;

        if ($this->mode == 'user')
            $data['user_id']    = $this->user_id;

        //dd($tds);


      //  $data['page_title']          = 'Statement of Deficiencies and Plan of Correction, QAPI Tool.';

        return view('shared/cms2567-new', $data);
    }


//----------------------------------------------------------------------------------------------------------------------
// update TDs.poc_override after editing (EDIT/SAVE buttons)


    public function updatePoc (Request $request)
    {
        $tds = $request->poc_override;

        $db = $db = DBFunctions::rootDB();
        $fac_uid = session('fac_uid');
        $facdb = 'facility_'.$fac_uid;

        if ($tds) foreach($tds as $td_uid => $override)
        {
	   if(!$override) $override = 0;
                $db->statement("UPDATE $facdb.task_details
                                SET poc_override = :override
                                WHERE uid = :td_uid",
                                [
                                    ':override' => $override,
                                    ':td_uid'   => $td_uid,
                                ]);

        }
        return response()->json(['data' => 'success'], 200);
    }




//----------------------------------------------------------------------------------------------------------------------
// collect params, pull the right data and return the view


    public function process (Request $request, $mode)
    {
        // task mode doesn't use date range but we still pass it
        $this->mode         = $mode;
        $this->start        = $request->start;
        $this->end          = $request->end;
        $this->dump         = $request->dump;
        $this->show_samples = $request->show_samples;

        if (!isset($this->show_samples))
            $this->show_samples = 1;

        if ($request->debug)
        {
            $this->debug = 1;
        }


        // all entries, all users
        if ($mode == 'all')
        {
            return $this->list();
        }

        // single-task mode
        if ($mode == 'task')
        {
            $this->td_id = $request->td_id;
            return $this->list();
        }

        // single-user mode
        if ($mode == 'user')
        {
            $this->user_id = $request->user_id;
            return $this->list();
        }

    }

//----------------------------------------------------------------------------------------------------------------------
// switch to ss() and use main view

    public function ss(Request $request)
    {
        $this->is_ss      = 1;
        $this->print_mode = 0;

        return $this->process($request, 'all');
    }


//----------------------------------------------------------------------------------------------------------------------
// members view by default - unless called via ss()


    public function index(Request $request)
    {
        $this->is_ss      = 0;
        $this->print_mode = 0;

        return $this->process($request, 'all');
    }

//----------------------------------------------------------------------------------------------------------------------
// Single Task mode (/task/<id>) - targets on td_id, not task! (confusing)


    public function task(Request $request)
    {
        $this->print_mode = 0;

        return $this->process($request, 'task');
    }


//----------------------------------------------------------------------------------------------------------------------
// Single User Mode (/user/<user_id>) - targets on td_id, not task! (confusing)


    public function user(Request $request)
    {
        $this->print_mode = 0;

        return $this->process($request, 'user');
    }


//----------------------------------------------------------------------------------------------------------------------

}
