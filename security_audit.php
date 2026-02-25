<?php
/**
 * Security Audit Class
 * Provides security warnings and audit functionality for the dashboard
 */
class SecurityAudit {
    /**
     * Get security warning HTML for the dashboard
     * 
     * @param string $basePath Base directory path for the application
     * @return string HTML warning message or empty string if no warnings
     */
    public static function getDashboardWarning($basePath) {
        $warnings = [];
        
        // Check for common security issues
        
        // Check if .env file is accessible (should not be in public directory)
        if (file_exists($basePath . '/.env')) {
            $htaccessPath = $basePath . '/.htaccess';
            if (!file_exists($htaccessPath)) {
                $warnings[] = '.env-Datei ist nicht geschützt - .htaccess fehlt';
            }
        }
        
        // Check for setup files that should be removed in production
        $setupFiles = [
            'setup_admin.php',
            'setup_production_db.php',
            'finalize_production_setup_v2.php'
        ];
        
        foreach ($setupFiles as $file) {
            if (file_exists($basePath . '/' . $file)) {
                $warnings[] = "Setup-Datei '{$file}' sollte in Produktion entfernt werden";
            }
        }
        
        // If no warnings, return empty string
        if (empty($warnings)) {
            return '';
        }
        
        // Build warning HTML
        $warningHtml = '<div class="max-w-4xl mx-auto mb-6">';
        $warningHtml .= '<div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-lg shadow-sm">';
        $warningHtml .= '<div class="flex items-start">';
        $warningHtml .= '<div class="flex-shrink-0">';
        $warningHtml .= '<i class="fas fa-exclamation-triangle text-red-500 text-xl"></i>';
        $warningHtml .= '</div>';
        $warningHtml .= '<div class="ml-3 flex-1">';
        $warningHtml .= '<h3 class="text-lg font-semibold text-red-800 mb-2">Sicherheitshinweise</h3>';
        $warningHtml .= '<ul class="list-disc list-inside space-y-1 text-red-700">';
        
        foreach ($warnings as $warning) {
            $warningHtml .= '<li>' . htmlspecialchars($warning) . '</li>';
        }
        
        $warningHtml .= '</ul>';
        $warningHtml .= '</div>';
        $warningHtml .= '</div>';
        $warningHtml .= '</div>';
        $warningHtml .= '</div>';
        
        return $warningHtml;
    }
}
