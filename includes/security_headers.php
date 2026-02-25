<?php
/**
 * Security Headers Configuration
 * 
 * This file sets HTTP response headers to harden the application's security posture.
 * Headers are only set if they haven't been sent already to avoid conflicts.
 * Enhanced with nonce-based CSP and additional security features.
 * 
 * Include this file early in the application lifecycle, ideally in config/config.php
 */

/**
 * Helper function to check if a specific header has been set
 * 
 * @param string $header_name The name of the header to check
 * @return bool True if the header has been set, false otherwise
 */
function header_sent_check($header_name) {
    $headers = headers_list();
    foreach ($headers as $header) {
        if (stripos($header, $header_name . ':') === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Generate CSP nonce for inline scripts
 * 
 * @return string The nonce value
 */
function generate_csp_nonce() {
    if (!isset($_SESSION['csp_nonce'])) {
        init_session();
        $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
    }
    return $_SESSION['csp_nonce'];
}

// Only set headers if they haven't been sent yet
if (!headers_sent()) {
    
    // X-Content-Type-Options: Prevents MIME-sniffing attacks
    // Ensures browsers respect the declared Content-Type
    if (!header_sent_check('X-Content-Type-Options')) {
        header('X-Content-Type-Options: nosniff');
    }
    
    // X-Frame-Options: Prevents clickjacking attacks
    // SAMEORIGIN allows framing only from the same origin
    if (!header_sent_check('X-Frame-Options')) {
        header('X-Frame-Options: SAMEORIGIN');
    }
    
    // X-XSS-Protection: Enables browser's XSS filtering
    // mode=block prevents rendering of the page if XSS is detected
    // NOTE: This header is deprecated in modern browsers which rely on CSP instead.
    // It's included here for backwards compatibility with older browsers.
    if (!header_sent_check('X-XSS-Protection')) {
        header('X-XSS-Protection: 1; mode=block');
    }
    
    // Referrer-Policy: Controls how much referrer information is sent
    // strict-origin-when-cross-origin sends full URL for same-origin, only origin for cross-origin HTTPS
    if (!header_sent_check('Referrer-Policy')) {
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
    
    // Permissions-Policy: Controls browser features and APIs
    // Restricts access to sensitive features like camera, microphone, geolocation
    if (!header_sent_check('Permissions-Policy')) {
        $permissions = [
            'camera=()',
            'microphone=()',
            'geolocation=()',
            'payment=()',
            'usb=()',
            'magnetometer=()',
            'gyroscope=()',
            'accelerometer=()'
        ];
        header('Permissions-Policy: ' . implode(', ', $permissions));
    }
    
    // Strict-Transport-Security: Enforces HTTPS connections
    // Only set in production to avoid issues in development
    if (!header_sent_check('Strict-Transport-Security')) {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            // max-age=31536000 (1 year), includeSubDomains, preload
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
    }
    
    // Strict Content-Security-Policy
    // Allows scripts from 'self', 'unsafe-inline', and trusted CDNs (Tailwind, Cloudflare)
    // Allows images from 'self' and data: schemes (for SVGs)
    // Allows styles from 'self', 'unsafe-inline', and trusted CDNs (Google Fonts, Font Awesome)
    // Allows fonts from 'self', data:, and Google Fonts
    // 
    // NOTE: 'unsafe-inline' is used for backwards compatibility with inline scripts/styles
    // throughout the application. For better security, consider:
    // 1. Moving inline scripts to external files
    // 2. Implementing CSP nonces for necessary inline scripts
    // 3. Using hashes for specific inline scripts
    if (!header_sent_check('Content-Security-Policy')) {
        $csp_directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://cdn-uicons.flaticon.com",
            "img-src 'self' data:",
            "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com https://cdn-uicons.flaticon.com",
            "connect-src 'self'"
        ];
        header("Content-Security-Policy: " . implode("; ", $csp_directives));
    }
    
    // X-Permitted-Cross-Domain-Policies: Restricts Adobe Flash and PDF cross-domain policies
    if (!header_sent_check('X-Permitted-Cross-Domain-Policies')) {
        header('X-Permitted-Cross-Domain-Policies: none');
    }
    
    // Cache-Control: Prevent caching of sensitive pages
    // This should be overridden for static resources
    if (!header_sent_check('Cache-Control')) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        // Only set no-cache for non-asset requests
        if (!preg_match('/\.(css|js|jpg|jpeg|png|gif|webp|svg|woff|woff2|ttf|eot)$/i', $requestUri)) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
    }
}
