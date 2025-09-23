<?php
/**
 * Basecamp Logger
 * Comprehensive logging and monitoring system
 *
 * @package WBComDesigns\BasecampAutomation
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Basecamp Logger Class
 */
class Basecamp_Logger {

    /**
     * Log levels
     */
    const LEVEL_DEBUG = 1;
    const LEVEL_INFO = 2;
    const LEVEL_WARNING = 3;
    const LEVEL_ERROR = 4;
    const LEVEL_CRITICAL = 5;

    /**
     * Log file path
     */
    private $log_file;

    /**
     * Performance metrics
     */
    private $metrics = [];

    /**
     * API usage tracking
     */
    private $api_calls = [];

    /**
     * Error counts
     */
    private $error_counts = [];

    /**
     * Constructor
     */
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/basecamp-logs';

        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        $this->log_file = $log_dir . '/basecamp-automation-' . date('Y-m-d') . '.log';
        $this->load_metrics();
    }

    /**
     * Load existing metrics from storage
     */
    private function load_metrics() {
        $this->metrics = get_option('bcr_performance_metrics', [
            'api_calls_today' => 0,
            'total_operations' => 0,
            'average_response_time' => 0,
            'last_reset' => date('Y-m-d')
        ]);

        $this->api_calls = get_option('bcr_api_calls_log', []);
        $this->error_counts = get_option('bcr_error_counts', []);

        // Reset daily metrics if it's a new day
        if ($this->metrics['last_reset'] !== date('Y-m-d')) {
            $this->reset_daily_metrics();
        }
    }

    /**
     * Reset daily metrics
     */
    private function reset_daily_metrics() {
        $this->metrics['api_calls_today'] = 0;
        $this->metrics['last_reset'] = date('Y-m-d');
        $this->api_calls = [];
        $this->save_metrics();
    }

    /**
     * Save metrics to storage
     */
    private function save_metrics() {
        update_option('bcr_performance_metrics', $this->metrics);
        update_option('bcr_api_calls_log', $this->api_calls);
        update_option('bcr_error_counts', $this->error_counts);
    }

    /**
     * Log a message
     */
    public function log($level, $message, $context = []) {
        $level_names = [
            self::LEVEL_DEBUG => 'DEBUG',
            self::LEVEL_INFO => 'INFO',
            self::LEVEL_WARNING => 'WARNING',
            self::LEVEL_ERROR => 'ERROR',
            self::LEVEL_CRITICAL => 'CRITICAL'
        ];

        $level_name = $level_names[$level] ?? 'UNKNOWN';
        $timestamp = date('Y-m-d H:i:s');
        $context_str = !empty($context) ? ' | Context: ' . json_encode($context) : '';

        $log_entry = "[{$timestamp}] {$level_name}: {$message}{$context_str}" . PHP_EOL;

        // Write to file
        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);

        // Track error counts
        if ($level >= self::LEVEL_ERROR) {
            $error_key = date('Y-m-d H');
            $this->error_counts[$error_key] = ($this->error_counts[$error_key] ?? 0) + 1;
            $this->save_metrics();
        }

        // Output to WP-CLI if available
        if (defined('WP_CLI') && WP_CLI) {
            $color = $this->get_cli_color($level);
            WP_CLI::line(WP_CLI::colorize("%{$color}{$level_name}:%n {$message}"));
        }
    }

    /**
     * Get CLI color for log level
     */
    private function get_cli_color($level) {
        switch ($level) {
            case self::LEVEL_DEBUG: return 'C';
            case self::LEVEL_INFO: return 'G';
            case self::LEVEL_WARNING: return 'Y';
            case self::LEVEL_ERROR: return 'R';
            case self::LEVEL_CRITICAL: return 'M';
            default: return 'N';
        }
    }

    /**
     * Log API call performance
     */
    public function log_api_call($endpoint, $method, $response_time, $status_code, $success = true) {
        $this->metrics['api_calls_today']++;
        $this->metrics['total_operations']++;

        // Calculate average response time
        $this->metrics['average_response_time'] =
            (($this->metrics['average_response_time'] * ($this->metrics['total_operations'] - 1)) + $response_time)
            / $this->metrics['total_operations'];

        // Store detailed API call info
        $call_data = [
            'timestamp' => time(),
            'endpoint' => $endpoint,
            'method' => $method,
            'response_time' => $response_time,
            'status_code' => $status_code,
            'success' => $success,
            'hour' => date('H')
        ];

        $this->api_calls[] = $call_data;

        // Keep only last 1000 calls to prevent memory issues
        if (count($this->api_calls) > 1000) {
            $this->api_calls = array_slice($this->api_calls, -1000);
        }

        $this->save_metrics();

        // Log slow API calls
        if ($response_time > 5) {
            $this->log(self::LEVEL_WARNING, "Slow API call detected", [
                'endpoint' => $endpoint,
                'response_time' => $response_time . 's',
                'status_code' => $status_code
            ]);
        }

        // Log failed API calls
        if (!$success) {
            $this->log(self::LEVEL_ERROR, "API call failed", [
                'endpoint' => $endpoint,
                'method' => $method,
                'status_code' => $status_code
            ]);
        }
    }

    /**
     * Log automation workflow execution
     */
    public function log_workflow($workflow_name, $project_id, $results, $execution_time) {
        $success_count = 0;
        $total_count = 0;

        if (is_array($results)) {
            foreach ($results as $result) {
                if (is_array($result)) {
                    $total_count += count($result);
                    foreach ($result as $item) {
                        if (isset($item['success']) && $item['success']) {
                            $success_count++;
                        }
                    }
                }
            }
        }

        $this->log(self::LEVEL_INFO, "Workflow executed: {$workflow_name}", [
            'project_id' => $project_id,
            'execution_time' => $execution_time . 's',
            'success_rate' => $total_count > 0 ? round(($success_count / $total_count) * 100, 1) . '%' : 'N/A',
            'operations' => $total_count
        ]);
    }

    /**
     * Log project analysis
     */
    public function log_project_analysis($project_id, $project_name, $analysis) {
        $this->log(self::LEVEL_INFO, "Project analyzed: {$project_name}", [
            'project_id' => $project_id,
            'total_cards' => $analysis['total_cards'],
            'completion_rate' => $analysis['completion_rate'] ?? 0,
            'health_score' => $analysis['health_score'],
            'overdue_count' => count($analysis['overdue'] ?? []),
            'unassigned_count' => count($analysis['unassigned'] ?? [])
        ]);

        // Log critical issues
        if (($analysis['health_score'] ?? 100) < 50) {
            $this->log(self::LEVEL_WARNING, "Project health critical: {$project_name}", [
                'project_id' => $project_id,
                'health_score' => $analysis['health_score']
            ]);
        }

        if (count($analysis['overdue'] ?? []) > 5) {
            $this->log(self::LEVEL_WARNING, "High number of overdue tasks: {$project_name}", [
                'project_id' => $project_id,
                'overdue_count' => count($analysis['overdue'])
            ]);
        }
    }

    /**
     * Get performance metrics
     */
    public function get_metrics() {
        return [
            'performance' => $this->metrics,
            'api_usage' => $this->get_api_usage_stats(),
            'error_rates' => $this->get_error_rates(),
            'system_health' => $this->get_system_health()
        ];
    }

    /**
     * Get API usage statistics
     */
    private function get_api_usage_stats() {
        $hourly_calls = [];
        $endpoint_usage = [];
        $method_usage = [];

        foreach ($this->api_calls as $call) {
            $hour = $call['hour'];
            $hourly_calls[$hour] = ($hourly_calls[$hour] ?? 0) + 1;

            $endpoint = $call['endpoint'];
            $endpoint_usage[$endpoint] = ($endpoint_usage[$endpoint] ?? 0) + 1;

            $method = $call['method'];
            $method_usage[$method] = ($method_usage[$method] ?? 0) + 1;
        }

        return [
            'calls_today' => $this->metrics['api_calls_today'],
            'average_response_time' => round($this->metrics['average_response_time'], 3),
            'hourly_distribution' => $hourly_calls,
            'top_endpoints' => array_slice($endpoint_usage, 0, 10, true),
            'methods' => $method_usage
        ];
    }

    /**
     * Get error rates
     */
    private function get_error_rates() {
        $total_errors = array_sum($this->error_counts);
        $recent_errors = [];

        // Get errors from last 24 hours
        $cutoff = time() - (24 * 60 * 60);
        foreach ($this->error_counts as $hour => $count) {
            $hour_timestamp = strtotime($hour . ':00:00');
            if ($hour_timestamp >= $cutoff) {
                $recent_errors[$hour] = $count;
            }
        }

        return [
            'total_errors' => $total_errors,
            'errors_last_24h' => array_sum($recent_errors),
            'hourly_errors' => $recent_errors,
            'error_rate' => $this->metrics['total_operations'] > 0
                ? round(($total_errors / $this->metrics['total_operations']) * 100, 2)
                : 0
        ];
    }

    /**
     * Get system health status
     */
    private function get_system_health() {
        $health_score = 100;
        $issues = [];

        // Check API response time
        if ($this->metrics['average_response_time'] > 3) {
            $health_score -= 20;
            $issues[] = 'High API response times';
        }

        // Check error rate
        $error_stats = $this->get_error_rates();
        if ($error_stats['error_rate'] > 5) {
            $health_score -= 30;
            $issues[] = 'High error rate';
        }

        // Check API usage
        if ($this->metrics['api_calls_today'] > 5000) {
            $health_score -= 10;
            $issues[] = 'High API usage';
        }

        // Check recent errors
        if ($error_stats['errors_last_24h'] > 10) {
            $health_score -= 15;
            $issues[] = 'Recent error spike';
        }

        $health_score = max(0, $health_score);

        return [
            'score' => $health_score,
            'status' => $health_score >= 80 ? 'excellent' :
                       ($health_score >= 60 ? 'good' :
                       ($health_score >= 40 ? 'warning' : 'critical')),
            'issues' => $issues,
            'last_check' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Generate monitoring report
     */
    public function generate_monitoring_report() {
        $metrics = $this->get_metrics();

        $report = [
            'report_date' => date('Y-m-d H:i:s'),
            'summary' => [
                'system_health' => $metrics['system_health']['status'],
                'health_score' => $metrics['system_health']['score'],
                'api_calls_today' => $metrics['performance']['api_calls_today'],
                'average_response_time' => $metrics['api_usage']['average_response_time'] . 's',
                'error_rate' => $metrics['error_rates']['error_rate'] . '%'
            ],
            'performance' => $metrics['performance'],
            'api_usage' => $metrics['api_usage'],
            'errors' => $metrics['error_rates'],
            'health_check' => $metrics['system_health']
        ];

        return $report;
    }

    /**
     * Clean up old logs
     */
    public function cleanup_logs($days_to_keep = 30) {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/basecamp-logs';

        if (!is_dir($log_dir)) return;

        $cutoff_date = time() - ($days_to_keep * 24 * 60 * 60);
        $files_cleaned = 0;

        $files = glob($log_dir . '/basecamp-automation-*.log');
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_date) {
                unlink($file);
                $files_cleaned++;
            }
        }

        $this->log(self::LEVEL_INFO, "Log cleanup completed", [
            'files_cleaned' => $files_cleaned,
            'days_kept' => $days_to_keep
        ]);

        return $files_cleaned;
    }

    /**
     * Export logs for analysis
     */
    public function export_logs($start_date = null, $end_date = null) {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/basecamp-logs';

        $start_date = $start_date ?: date('Y-m-d', strtotime('-7 days'));
        $end_date = $end_date ?: date('Y-m-d');

        $export_data = [
            'export_date' => date('Y-m-d H:i:s'),
            'date_range' => ['start' => $start_date, 'end' => $end_date],
            'logs' => [],
            'metrics' => $this->get_metrics()
        ];

        $current_date = new DateTime($start_date);
        $end_date_obj = new DateTime($end_date);

        while ($current_date <= $end_date_obj) {
            $log_file = $log_dir . '/basecamp-automation-' . $current_date->format('Y-m-d') . '.log';

            if (file_exists($log_file)) {
                $export_data['logs'][$current_date->format('Y-m-d')] = file_get_contents($log_file);
            }

            $current_date->modify('+1 day');
        }

        return $export_data;
    }

    /**
     * Monitor system alerts
     */
    public function check_alerts() {
        $alerts = [];
        $metrics = $this->get_metrics();

        // High error rate alert
        if ($metrics['error_rates']['error_rate'] > 10) {
            $alerts[] = [
                'level' => 'critical',
                'message' => 'High error rate detected',
                'data' => ['error_rate' => $metrics['error_rates']['error_rate'] . '%']
            ];
        }

        // Slow API response alert
        if ($metrics['api_usage']['average_response_time'] > 5) {
            $alerts[] = [
                'level' => 'warning',
                'message' => 'Slow API responses detected',
                'data' => ['response_time' => $metrics['api_usage']['average_response_time'] . 's']
            ];
        }

        // High API usage alert
        if ($metrics['performance']['api_calls_today'] > 4000) {
            $alerts[] = [
                'level' => 'warning',
                'message' => 'High API usage approaching limits',
                'data' => ['calls_today' => $metrics['performance']['api_calls_today']]
            ];
        }

        // System health alert
        if ($metrics['system_health']['score'] < 50) {
            $alerts[] = [
                'level' => 'critical',
                'message' => 'System health critical',
                'data' => [
                    'health_score' => $metrics['system_health']['score'],
                    'issues' => $metrics['system_health']['issues']
                ]
            ];
        }

        foreach ($alerts as $alert) {
            $level = $alert['level'] === 'critical' ? self::LEVEL_CRITICAL : self::LEVEL_WARNING;
            $this->log($level, $alert['message'], $alert['data']);
        }

        return $alerts;
    }

    /**
     * Debug helper methods
     */
    public function debug($message, $context = []) {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }

    public function info($message, $context = []) {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    public function warning($message, $context = []) {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    public function error($message, $context = []) {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    public function critical($message, $context = []) {
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }
}