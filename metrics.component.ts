import { DashboardService } from 'src/app/services/dashboard.service';
import { ChangeDetectionStrategy, Component, Input, OnDestroy, OnInit, ViewChild } from '@angular/core';
import * as Highcharts from 'highcharts';
import { BehaviorSubject, Observable, Subject } from 'rxjs';
import { filter, map, skip, switchMap, takeUntil, tap } from 'rxjs/operators';
import { calculateDates, createChartOptions, transformData } from 'src/app/helpers/chart_helpers';
import { ChartDateRange, ChartDateRangeSelection, TotalUsersFromMetrics } from 'src/app/models/common/chart-date-range';
import { Rule } from 'src/app/models/common/rule';
import {
  GetMetricsParams,
  GetMetricsResponse,
  MetricsDataService,
  TotalsByDateRange
} from 'src/app/services/metrics-data.service';

@Component({
  selector: 'at-metrics',
  templateUrl: './metrics.component.html',
  styleUrls: ['./metrics.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class MetricsComponent implements OnInit, OnDestroy {
  @Input() metricType: string;
  moduleCompletions = MetricsDataService.MODULE_COMPLETIONS;
  @Input() widgetId: number;
  @Input() settingsKey: string;
  @Input() chartType = 'line';
  @Input() seriesName = 'metrics';

  Highcharts: typeof Highcharts = Highcharts;
  chartOptions: Highcharts.Options = createChartOptions([]);
  chartRef: Highcharts.Chart;
  updateFlag = false;
  params$ = new BehaviorSubject<GetMetricsParams>(null);
  metrics$: Observable<GetMetricsResponse>;
  fetchingNewMetrics = false;
  trends$: Observable<TotalsByDateRange>;
  fetchingNewTrends = false;

  private initParams: GetMetricsParams;
  private unsubscribe$ = new Subject<void>();

  chartCallback: Highcharts.ChartCallbackFunction = chart => {
    this.chartRef = chart;
  };
  userTotalForDateRange: TotalUsersFromMetrics = { currentTotalToDisplay: 0 };

  constructor(private metricsDataService: MetricsDataService, private dashboardService: DashboardService) {}

  ngOnDestroy() {
    this.unsubscribe$.next();
    this.unsubscribe$.complete();
  }

  ngOnInit() {
    this.setupInitialParams();
    this.params$.next(this.initParams);

    this.trends$ = this.params$.asObservable().pipe(
      switchMap(p => {
        this.fetchingNewTrends = true;
        return this.metricsDataService.getTrends(this.metricType, p).pipe(
          tap(() => {
            this.fetchingNewTrends = false;
          })
        );
      })
    );

    this.metrics$ = this.params$
      .asObservable()
      .pipe(
        switchMap(p => {
          this.fetchingNewMetrics = true;
          return this.metricsDataService.getMetrics(this.metricType, p).pipe(
            tap(() => {
              this.fetchingNewMetrics = false;
            })
          );
        })
      )
      .pipe(
        tap(metrics => {
          this.updateChart(metrics.csv);
        })
      );

    // react to param changes and persist settings
    this.params$
      .pipe(
        takeUntil(this.unsubscribe$), // clean code unsubscribe
        filter(p => p !== null), // obviously
        skip(1), // ignore initial value from settings
        tap(p => this.dashboardService.settingsChanged(this.widgetId, p, this.settingsKey))
      )
      .subscribe();
  }

  private setupInitialParams(): void {
    const settings = this.dashboardService.getSettings(this.widgetId, this.settingsKey);
    const rangeSelection = settings?.rangeSelection || this.buildRangeSelection();
    let audienceRule = settings?.audienceRule;
    if (typeof audienceRule === 'string' || audienceRule instanceof String) {
      // backward compatibility with earlier version of settings.audienceRule
      audienceRule = JSON.parse(atob(settings.audienceRule));
    }
    this.initParams = { audienceRule, rangeSelection };
  }

  private updateChart(data: number[][]) {
    if (!data) return;
    transformData(
      data,
      this.chartOptions,
      'Metrics',
      this.chartType,
      this.seriesName,
      this.currentRange,
      this.userTotalForDateRange
    );
    this.updateFlag = true;
  }

  rangeChanged(rangeSelection: ChartDateRangeSelection) {
    this.params$.next({ ...this.params$.value, rangeSelection });
    this.updateSettingAndChartStatus(this.params$.getValue());
  }

  ruleChanged(audienceRule: Rule) {
    this.params$.next({ ...this.params$.value, audienceRule });
    this.updateSettingAndChartStatus(this.params$.getValue());
  }

  buildRangeSelection(range = ChartDateRange.WEEK): ChartDateRangeSelection {
    const dates = calculateDates(range);
    const start_date = dates.start_date;
    const end_date = dates.end_date;
    return { range, start_date, end_date };
  }

  get initRange(): ChartDateRangeSelection {
    return this.initParams.rangeSelection;
  }

  get initRule() {
    return this.initParams.audienceRule;
  }

  get currentRange() {
    return this.params$.value?.rangeSelection;
  }

  private updateSettingAndChartStatus(value: any) {
    if (this.chartRef) {
      this.chartRef.isUpdated = false; // Reset the update flag for chart
      if (this.chartRef.drilldownLevels && this.chartRef.drilldownLevels.length) {
        this.chartRef.drillUp();
      }
    }
  }
}
