<?php
/**
 * Plugin Name: ContactFinder AI
 * Description: Scans a homepage and up to two relevant About/Contact pages.
 * Version: 0.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validate a public HTTP/HTTPS website URL.
 */
function cfai_validate_url($url) {
    $url = esc_url_raw(trim((string) $url));

    if (!$url || !wp_http_validate_url($url)) {
        return false;
    }

    $parts = wp_parse_url($url);

    if (
        empty($parts['host']) ||
        !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) ||
        isset($parts['user']) ||
        isset($parts['pass'])
    ) {
        return false;
    }

    $host = strtolower($parts['host']);

    if (
        $host === 'localhost' ||
        substr($host, -10) === '.localhost' ||
        substr($host, -6) === '.local' ||
        filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
    ) {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        )) {
            return false;
        }
    } else {
        $ips = [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);

            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip'])) {
                        $ips[] = $record['ip'];
                    }
                    if (!empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if (!$ips) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )) {
                return false;
            }
        }
    }

    return $url;
}

/**
 * Fetch one page, without following redirects.
 */
function cfai_fetch_page($url, $expected_host) {
    $url = cfai_validate_url($url);

    if (!$url || strtolower(wp_parse_url($url, PHP_URL_HOST)) !== strtolower($expected_host)) {
        return '';
    }

    $response = wp_safe_remote_get($url, [
        'timeout'             => 8,
        'redirection'         => 0,
        'limit_response_size' => 500000,
        'reject_unsafe_urls'  => true,
        'headers'             => [
            'User-Agent' => 'ContactFinderAI/0.5 (+public website contact scanner)'
        ],
    ]);

    if (is_wp_error($response)) {
        return '';
    }

    $status = wp_remote_retrieve_response_code($response);

    if ($status < 200 || $status >= 300) {
        return '';
    }

    $type = wp_remote_retrieve_header($response, 'content-type');

    if ($type && stripos($type, 'text/html') === false) {
        return '';
    }

    return (string) wp_remote_retrieve_body($response);
}

/**
 * Resolve a discovered link against the homepage URL.
 */
function cfai_resolve_link($href, $base_url) {
    $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if (
        $href === '' ||
        strpos($href, '#') === 0 ||
        preg_match('/^(mailto:|tel:|javascript:|data:)/i', $href)
    ) {
        return '';
    }

    if (strpos($href, '//') === 0) {
        $scheme = wp_parse_url($base_url, PHP_URL_SCHEME);
        $href = $scheme . ':' . $href;
    } elseif (strpos($href, '/') === 0) {
        $parts = wp_parse_url($base_url);
        $href = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . $href;
    } elseif (!preg_match('#^https?://#i', $href)) {
        $href = trailingslashit($base_url) . $href;
    }

    $href = preg_replace('/#.*$/', '', $href);
    $parts = wp_parse_url($href);

    if (empty($parts['host'])) {
        return '';
    }

    return cfai_validate_url($href) ?: '';
}

/**
 * Find relevant About and Contact links on the homepage.
 */
function cfai_find_priority_pages($html, $home_url) {
    $host = wp_parse_url($home_url, PHP_URL_HOST);
    $found = ['about' => '', 'contact' => ''];

    if (class_exists('DOMDocument')) {
        $dom = new DOMDocument();

        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        if ($loaded) {
            foreach ($dom->getElementsByTagName('a') as $a) {
                $href = $a->getAttribute('href');
                $text = strtolower(trim($a->textContent . ' ' . $a->getAttribute('aria-label')));
                $url = cfai_resolve_link($href, $home_url);

                if (!$url || strtolower(wp_parse_url($url, PHP_URL_HOST)) !== strtolower($host)) {
                    continue;
                }

                $path = strtolower(wp_parse_url($url, PHP_URL_PATH) ?: '/');
                $haystack = $text . ' ' . $path;

                if (
                    !$found['about'] &&
                    preg_match('/about|over-ons|uber-uns|ueber-uns|azienda|bedrijf|who-we-are|impressum|ueber-uns/i', $haystack)
                ) {
                    $found['about'] = $url;
                }

                if (
                    !$found['contact'] &&
                    preg_match('/contact|kontakt|contatti|contacto|contactez|customer-service/i', $haystack)
                ) {
                    $found['contact'] = $url;
                }
            }
        }
    }

    // Common path fallbacks if a suitable link was not found.
    $base = untrailingslashit($home_url);

    if (!$found['about']) {
        $found['about'] = $base . '/about/';
    }

    if (!$found['contact']) {
        $found['contact'] = $base . '/contact/';
    }

    return $found;
}

/**
 * Extract contact details and source URLs from a page.
 */
function cfai_extract_page_data($html, $source_url) {
    $result = [
        'emails' => [],
        'phones' => [],
        'social_links' => [],
    ];

    // Decode HTML entities before extracting contact details.
    $decoded_html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Emails from mailto links and page text.
    preg_match_all('/mailto:([^"\'\s?]+)/i', $decoded_html, $mailto);
    preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $decoded_html, $emails);
    $result['emails'] = array_merge($mailto[1] ?? [], $emails[0] ?? []);

    // Explicit tel: links are the most reliable phone source. Decode %2B / %20.
    preg_match_all('/href\s*=\s*["\']([^"\']*tel:[^"\']+)["\']/i', $decoded_html, $tel_links);
    foreach (($tel_links[1] ?? []) as $tel_link) {
        $parts = explode('tel:', $tel_link, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $phone = rawurldecode($parts[1]);
        $phone = preg_replace('/[?#].*$/', '', $phone);
        $phone = trim($phone);
        $digits = preg_replace('/\D/', '', $phone);
        if (preg_match('/^\+?[\d().\s\-\/]{7,25}$/', $phone) && strlen($digits) >= 8 && strlen($digits) <= 15) {
            $result['phones'][] = $phone;
        }
    }

    // Visible-text fallback. Remove dates/year ranges before finding phone candidates.
    $text = rawurldecode(wp_strip_all_tags($decoded_html));
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Dates such as 01.09.2024, 01/09/2024, 2024-09-01.
    $text = preg_replace('/\b\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}\b/', ' ', $text);
    $text = preg_replace('/\b(?:19|20)\d{2}[.\/-]\d{1,2}[.\/-]\d{1,2}\b/', ' ', $text);
    // Year ranges such as 1956-1963 / 1956–1963 and standalone years.
    $text = preg_replace('/\b(?:19|20)\d{2}\s*[-–—]\s*(?:19|20)\d{2}\b/u', ' ', $text);
    $text = preg_replace('/\b(?:19|20)\d{2}\b/', ' ', $text);

    preg_match_all('/(?<!\d)(?:\+\d{1,3}[\s().\/-]*)?(?:\(?\d{2,5}\)?[\s().\/-]*){2,5}\d{2,5}(?!\d)/', $text, $matches);
    foreach (($matches[0] ?? []) as $candidate) {
        $phone = trim(preg_replace('/\s+/', ' ', $candidate));
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            continue;
        }
        // Avoid candidates that still contain a four-digit year.
        if (preg_match('/\b(?:19|20)\d{2}\b/', $phone)) {
            continue;
        }
        $result['phones'][] = $phone;
    }

    // Social profile links.
    preg_match_all('/https?:\/\/[^"\'\s<>]+/i', $decoded_html, $links);
    $domains = ['linkedin.com', 'facebook.com', 'instagram.com', 'youtube.com', 'youtu.be', 'x.com', 'twitter.com', 'tiktok.com'];
    foreach (($links[0] ?? []) as $link) {
        $link = rtrim(html_entity_decode($link, ENT_QUOTES, 'UTF-8'), '.,);');
        $host = strtolower(wp_parse_url($link, PHP_URL_HOST) ?: '');
        foreach ($domains as $domain) {
            if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
                $result['social_links'][] = strtok($link, '?#');
                break;
            }
        }
    }

    // Normalize, deduplicate, and attach source URLs. Phone duplicates are keyed by digits.
    foreach ($result as $type => $values) {
        $clean = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($type === 'emails') {
                $value = strtolower(sanitize_email($value));
                if (!$value) {
                    continue;
                }
                $key = $value;
            } elseif ($type === 'phones') {
                $value = preg_replace('/\s+/', ' ', $value);
                $digits = preg_replace('/\D/', '', $value);
                if (strlen($digits) < 8 || strlen($digits) > 15) {
                    continue;
                }
                $key = $digits;
            } else {
                $value = esc_url_raw($value);
                if (!$value) {
                    continue;
                }
                $key = strtolower($value);
            }
            if (!isset($clean[$key])) {
                $clean[$key] = ['value' => $value, 'source' => $source_url];
            }
        }
        $result[$type] = array_values($clean);
    }

    return $result;
}

/**
 * REST API: scan homepage plus up to two relevant pages.
 */
function cfai_scan_website(WP_REST_Request $request) {
    $logged_in = is_user_logged_in() && current_user_can('read');

    $nonce = $request->get_header('X-WP-Nonce');
    if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
        return new WP_Error('invalid_nonce', 'Security check failed. Refresh the page and try again.', ['status' => 403]);
    }

    // Anonymous visitors may complete one successful scan per IP during the 30-day window.
    // This is server-side enforcement, but shared IPs can share the limit and VPN/IP changes can bypass it.
    $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $guest_key = 'cfai_guest_used_' . md5($ip);
    if (!$logged_in && get_transient($guest_key)) {
        return new WP_Error('guest_limit', 'Your free guest scan has been used. Please log in or register to scan more websites.', ['status' => 403]);
    }

    $rate_key = 'cfai_rate_' . md5($ip);

    if (get_transient($rate_key)) {
        return new WP_Error('rate_limited', 'Please wait one minute before scanning again.', ['status' => 429]);
    }

    set_transient($rate_key, 1, MINUTE_IN_SECONDS);

    $url = cfai_validate_url($request->get_param('url'));

    if (!$url) {
        return new WP_Error('invalid_url', 'Enter a valid public HTTP/HTTPS website URL.', ['status' => 400]);
    }

    $host = wp_parse_url($url, PHP_URL_HOST);
    $home_html = cfai_fetch_page($url, $host);

    if ($home_html === '') {
        return new WP_Error('fetch_failed', 'Could not read the homepage. Check the URL and try again.', ['status' => 400]);
    }

    $pages = [$url => $home_html];
    $priority = cfai_find_priority_pages($home_html, $url);

    foreach (['about', 'contact'] as $type) {
        if (count($pages) >= 3) {
            break;
        }

        $page_url = $priority[$type];

        if (!$page_url || isset($pages[$page_url])) {
            continue;
        }

        $page_html = cfai_fetch_page($page_url, $host);

        if ($page_html !== '') {
            $pages[$page_url] = $page_html;
        }
    }

    $all = [
        'emails' => [],
        'phones' => [],
        'social_links' => [],
    ];

    foreach ($pages as $page_url => $html) {
        $data = cfai_extract_page_data($html, $page_url);

        foreach ($all as $key => $_) {
            foreach ($data[$key] as $item) {
                $value = $item['value'];
                $dedupe_key = strtolower($value);

                if (!isset($all[$key][$dedupe_key])) {
                    $all[$key][$dedupe_key] = $item;
                }
            }
        }
    }

    foreach ($all as $key => $items) {
        $all[$key] = array_values($items);
    }

    $response_data = [
        'success'       => true,
        'message'       => 'Website scan completed.',
        'pages_scanned' => count($pages),
        'scanned_pages' => array_keys($pages),
        'emails'        => $all['emails'],
        'phones'        => $all['phones'],
        'social_links'  => $all['social_links'],
    ];

    // Mark the guest allowance only after a successful scan.
    if (!$logged_in) {
        set_transient($guest_key, 1, 30 * DAY_IN_SECONDS);
        $response_data['guest_scans_remaining'] = 0;
    }

    // Keep a limited, private scan history for the current user (up to 20 scans).
    $user_id = get_current_user_id();
    if ($user_id > 0) {
        $history = get_user_meta($user_id, 'cfai_scan_history', true);
        if (!is_array($history)) {
            $history = [];
        }

        array_unshift($history, [
            'id'             => wp_generate_uuid4(),
            'scanned_at'     => current_time('mysql'),
            'website_url'    => $url,
            'pages_scanned'  => count($pages),
            'scanned_pages'  => array_keys($pages),
            'emails'         => $all['emails'],
            'phones'         => $all['phones'],
            'social_links'   => $all['social_links'],
        ]);

        $history = array_slice($history, 0, 20);
        update_user_meta($user_id, 'cfai_scan_history', $history);
    }

    return rest_ensure_response($response_data);
}

add_action('rest_api_init', function () {
    register_rest_route('contactfinder/v1', '/scan', [
        'methods'             => 'POST',
        'callback'            => 'cfai_scan_website',
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Shortcode: [contactfinder_scanner]
 */
function cfai_scanner_shortcode() {
    $endpoint = esc_url(rest_url('contactfinder/v1/scan'));
    $nonce = wp_create_nonce('wp_rest');
    $uid = function_exists('wp_unique_id') ? wp_unique_id('cfai-') : 'cfai-' . wp_rand(1000, 999999);

    ob_start();
    ?>
    <div id="<?php echo esc_attr($uid); ?>" class="cfai-app">
      <style>
        #<?php echo esc_attr($uid); ?>{--cfai-ink:#172033;--cfai-muted:#68758b;--cfai-line:#e6eaf2;--cfai-primary:#5b5ce2;--cfai-primary2:#7c3aed;--cfai-soft:#f5f6ff;color:var(--cfai-ink);font-family:inherit;width:100%;max-width:1080px;margin:24px auto}
        #<?php echo esc_attr($uid); ?> *{box-sizing:border-box}
        #<?php echo esc_attr($uid); ?> .cfai-hero{position:relative;overflow:hidden;padding:clamp(24px,5vw,48px);border-radius:24px;background:linear-gradient(125deg,#171d3a 0%,#34358d 55%,#6d45c7 100%);color:#fff;box-shadow:0 18px 45px rgba(49,48,126,.18)}
        #<?php echo esc_attr($uid); ?> .cfai-hero:after{content:"";position:absolute;width:270px;height:270px;border:1px solid rgba(255,255,255,.16);border-radius:50%;right:-75px;top:-115px;box-shadow:0 0 0 35px rgba(255,255,255,.035),0 0 0 70px rgba(255,255,255,.025);pointer-events:none}
        #<?php echo esc_attr($uid); ?> .cfai-eyebrow{display:inline-flex;align-items:center;gap:8px;padding:7px 11px;border:1px solid rgba(255,255,255,.22);border-radius:99px;background:rgba(255,255,255,.09);font-size:12px;font-weight:700;letter-spacing:.07em;text-transform:uppercase}
        #<?php echo esc_attr($uid); ?> .cfai-eyebrow-dot{width:7px;height:7px;border-radius:50%;background:#7df0c5;box-shadow:0 0 0 4px rgba(125,240,197,.13)}
        #<?php echo esc_attr($uid); ?> .cfai-hero h2{color:#fff;font-size:clamp(28px,4vw,42px);line-height:1.12;letter-spacing:-.035em;margin:20px 0 12px;font-weight:800}
        #<?php echo esc_attr($uid); ?> .cfai-hero p{max-width:610px;color:rgba(255,255,255,.78);font-size:16px;line-height:1.65;margin:0}
        #<?php echo esc_attr($uid); ?> .cfai-search-card{position:relative;margin:30px 22px 0;padding:26px;background:#fff;border:1px solid rgba(230,234,242,.9);border-radius:18px;box-shadow:0 12px 34px rgba(23,32,51,.10);z-index:1}
        #<?php echo esc_attr($uid); ?> .cfai-form label{display:block;font-size:13px;font-weight:750;color:#38445a;margin:0 0 9px}
        #<?php echo esc_attr($uid); ?> .cfai-input-row{display:flex;gap:10px;align-items:stretch}
        #<?php echo esc_attr($uid); ?> .cfai-url{flex:1;min-width:0;width:100%;min-height:54px!important;padding:0 16px!important;border:1px solid #dce2ed!important;border-radius:12px!important;background:#fbfcff!important;color:#172033!important;font-size:15px!important;box-shadow:none!important;outline:none;transition:border-color .2s,box-shadow .2s}
        #<?php echo esc_attr($uid); ?> .cfai-url:focus{border-color:var(--cfai-primary)!important;box-shadow:0 0 0 4px rgba(91,92,226,.12)!important}
        #<?php echo esc_attr($uid); ?> .cfai-submit{display:inline-flex;justify-content:center;align-items:center;gap:9px;min-height:54px;padding:0 22px;border:0!important;border-radius:12px!important;background:linear-gradient(120deg,var(--cfai-primary),var(--cfai-primary2))!important;color:#fff!important;font-size:14px;font-weight:750;white-space:nowrap;cursor:pointer;box-shadow:0 7px 16px rgba(91,92,226,.23);transition:transform .18s,opacity .18s}
        #<?php echo esc_attr($uid); ?> .cfai-submit:hover{transform:translateY(-1px);color:#fff!important}
        #<?php echo esc_attr($uid); ?> .cfai-submit:disabled{opacity:.65;cursor:wait;transform:none}
        #<?php echo esc_attr($uid); ?> .cfai-submit svg{width:17px;height:17px}
        #<?php echo esc_attr($uid); ?> .cfai-helper{display:flex;align-items:center;gap:8px;margin:12px 0 0;color:var(--cfai-muted);font-size:12px;line-height:1.5}
        #<?php echo esc_attr($uid); ?> .cfai-helper svg{width:15px;height:15px;flex:0 0 auto;color:var(--cfai-primary)}
        #<?php echo esc_attr($uid); ?> .cfai-status{min-height:22px;margin:16px 2px 0;color:var(--cfai-muted);font-size:13px}
        #<?php echo esc_attr($uid); ?> .cfai-status[data-kind="error"]{color:#b42318}
        #<?php echo esc_attr($uid); ?> .cfai-status[data-kind="success"]{color:#087443}
        #<?php echo esc_attr($uid); ?> .cfai-loading{display:none;align-items:center;gap:10px;margin:10px 0 0;color:#4b50b9;font-size:13px;font-weight:650}
        #<?php echo esc_attr($uid); ?> .cfai-loading.is-visible{display:flex}
        #<?php echo esc_attr($uid); ?> .cfai-spinner{width:18px;height:18px;border:2px solid #dfe1ff;border-top-color:var(--cfai-primary);border-radius:50%;animation:cfai-spin .7s linear infinite}
        @keyframes cfai-spin{to{transform:rotate(360deg)}}
        #<?php echo esc_attr($uid); ?> .cfai-results{margin-top:24px}
        #<?php echo esc_attr($uid); ?> .cfai-results:empty{display:none}
        #<?php echo esc_attr($uid); ?> .cfai-results-top{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin:0 0 14px}
        #<?php echo esc_attr($uid); ?> .cfai-results-top h3{margin:0;color:var(--cfai-ink);font-size:22px;font-weight:800;letter-spacing:-.025em}
        #<?php echo esc_attr($uid); ?> .cfai-pages-pill{display:inline-flex;align-items:center;gap:6px;border:1px solid #dceee5;border-radius:99px;background:#f0fbf5;color:#147849;padding:7px 10px;font-size:12px;font-weight:750}
        #<?php echo esc_attr($uid); ?> .cfai-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px;margin-bottom:18px}
        #<?php echo esc_attr($uid); ?> .cfai-stat{display:flex;align-items:center;gap:13px;min-width:0;padding:17px;border:1px solid var(--cfai-line);border-radius:16px;background:#fff;box-shadow:0 4px 15px rgba(23,32,51,.035)}
        #<?php echo esc_attr($uid); ?> .cfai-stat-icon{display:grid;place-items:center;width:42px;height:42px;flex:0 0 auto;border-radius:13px;background:#eeefff;color:#5758d6}
        #<?php echo esc_attr($uid); ?> .cfai-stat:nth-child(2) .cfai-stat-icon{background:#e9f8f1;color:#138557}
        #<?php echo esc_attr($uid); ?> .cfai-stat:nth-child(3) .cfai-stat-icon{background:#f4eefe;color:#8444bf}
        #<?php echo esc_attr($uid); ?> .cfai-stat-icon svg{width:21px;height:21px}
        #<?php echo esc_attr($uid); ?> .cfai-stat-number{display:block;font-size:25px;font-weight:850;line-height:1.15;letter-spacing:-.03em;color:var(--cfai-ink)}
        #<?php echo esc_attr($uid); ?> .cfai-stat-label{display:block;margin-top:4px;color:var(--cfai-muted);font-size:12px;font-weight:650}
        #<?php echo esc_attr($uid); ?> .cfai-result-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px;align-items:start}
        #<?php echo esc_attr($uid); ?> .cfai-result-card{min-width:0;overflow:hidden;border:1px solid var(--cfai-line);border-radius:16px;background:#fff;box-shadow:0 4px 16px rgba(23,32,51,.035)}
        #<?php echo esc_attr($uid); ?> .cfai-result-card.cfai-social-card{grid-column:1/-1}
        #<?php echo esc_attr($uid); ?> .cfai-card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 17px;border-bottom:1px solid #edf0f5;background:linear-gradient(180deg,#fff,#fcfcff)}
        #<?php echo esc_attr($uid); ?> .cfai-card-title{display:flex;align-items:center;gap:10px;min-width:0}
        #<?php echo esc_attr($uid); ?> .cfai-card-title h4{margin:0;font-size:15px;font-weight:800;color:var(--cfai-ink)}
        #<?php echo esc_attr($uid); ?> .cfai-card-mini-icon{display:grid;place-items:center;width:32px;height:32px;border-radius:10px;background:var(--cfai-soft);color:var(--cfai-primary)}
        #<?php echo esc_attr($uid); ?> .cfai-card-mini-icon svg{width:17px;height:17px}
        #<?php echo esc_attr($uid); ?> .cfai-count{display:inline-flex;justify-content:center;align-items:center;min-width:28px;height:26px;padding:0 8px;border-radius:99px;background:#f0f1ff;color:#4f50bf;font-size:12px;font-weight:800}
        #<?php echo esc_attr($uid); ?> .cfai-item-list{list-style:none!important;margin:0!important;padding:4px 17px!important}
        #<?php echo esc_attr($uid); ?> .cfai-item{display:flex;align-items:flex-start;gap:10px;padding:13px 0;border-bottom:1px solid #f0f2f6;min-width:0}
        #<?php echo esc_attr($uid); ?> .cfai-item:last-child{border-bottom:0}
        #<?php echo esc_attr($uid); ?> .cfai-item-main{flex:1;min-width:0}
        #<?php echo esc_attr($uid); ?> .cfai-value{display:block;overflow-wrap:anywhere;color:#26334b;font-size:13px;line-height:1.55;text-decoration:none;font-weight:650}
        #<?php echo esc_attr($uid); ?> a.cfai-value:hover{color:var(--cfai-primary);text-decoration:underline}
        #<?php echo esc_attr($uid); ?> .cfai-source{display:block;margin-top:4px;overflow-wrap:anywhere;color:#8791a4;font-size:11px;line-height:1.45}
        #<?php echo esc_attr($uid); ?> .cfai-source a{color:#7a8498;text-decoration:none}
        #<?php echo esc_attr($uid); ?> .cfai-source a:hover{text-decoration:underline;color:var(--cfai-primary)}
        #<?php echo esc_attr($uid); ?> .cfai-copy{display:inline-flex;align-items:center;justify-content:center;gap:5px;flex:0 0 auto;padding:6px 8px;border:1px solid #e2e6ef;border-radius:8px;background:#fff;color:#68758b;font-size:11px;font-weight:700;cursor:pointer}
        #<?php echo esc_attr($uid); ?> .cfai-copy:hover{border-color:#c7c8fa;background:#f7f7ff;color:#4f50bf}
        #<?php echo esc_attr($uid); ?> .cfai-copy svg{width:13px;height:13px}
        #<?php echo esc_attr($uid); ?> .cfai-empty{padding:24px 17px;text-align:center;color:var(--cfai-muted);font-size:13px}
        #<?php echo esc_attr($uid); ?> .cfai-empty strong{display:block;margin-bottom:5px;color:#4a566b;font-size:13px}
        #<?php echo esc_attr($uid); ?> .cfai-scanned-pages{margin-top:16px;padding:15px 17px;border:1px solid var(--cfai-line);border-radius:14px;background:#fafbfe}
        #<?php echo esc_attr($uid); ?> .cfai-scanned-pages h4{margin:0 0 9px;font-size:13px;font-weight:800;color:#354158}
        #<?php echo esc_attr($uid); ?> .cfai-page-chip{display:inline-block;max-width:100%;overflow-wrap:anywhere;margin:4px 5px 0 0;padding:6px 9px;border:1px solid #e2e7f0;border-radius:8px;background:#fff;color:#637087;font-size:11px;text-decoration:none}
        #<?php echo esc_attr($uid); ?> .cfai-login-note{max-width:760px;margin:20px auto;padding:18px;border:1px solid #e6eaf2;border-radius:12px;background:#f8f9ff;color:#39445a}
        @media(max-width:680px){#<?php echo esc_attr($uid); ?>{margin:12px auto}#<?php echo esc_attr($uid); ?> .cfai-hero{border-radius:18px;padding:25px 20px 34px}#<?php echo esc_attr($uid); ?> .cfai-hero h2{font-size:30px}#<?php echo esc_attr($uid); ?> .cfai-hero p{font-size:14px}#<?php echo esc_attr($uid); ?> .cfai-search-card{margin:22px 8px 0;padding:19px;border-radius:16px}#<?php echo esc_attr($uid); ?> .cfai-input-row{flex-direction:column}#<?php echo esc_attr($uid); ?> .cfai-submit{width:100%}#<?php echo esc_attr($uid); ?> .cfai-stats{gap:8px}#<?php echo esc_attr($uid); ?> .cfai-stat{align-items:flex-start;flex-direction:column;gap:9px;padding:12px}#<?php echo esc_attr($uid); ?> .cfai-stat-icon{width:34px;height:34px}#<?php echo esc_attr($uid); ?> .cfai-stat-number{font-size:22px}#<?php echo esc_attr($uid); ?> .cfai-stat-label{font-size:11px}#<?php echo esc_attr($uid); ?> .cfai-result-grid{grid-template-columns:1fr}#<?php echo esc_attr($uid); ?> .cfai-result-card.cfai-social-card{grid-column:auto}#<?php echo esc_attr($uid); ?> .cfai-results-top{align-items:flex-start;flex-direction:column}}
        @media(prefers-reduced-motion:reduce){#<?php echo esc_attr($uid); ?> *,#<?php echo esc_attr($uid); ?> *:before,#<?php echo esc_attr($uid); ?> *:after{animation-duration:.01ms!important;transition-duration:.01ms!important}}
      </style>
      <section class="cfai-hero">
        <span class="cfai-eyebrow"><span class="cfai-eyebrow-dot"></span> Smart lead research</span>
        <h2>Find business contacts<br>in seconds.</h2>
        <p>Discover publicly listed emails, phone numbers and social profiles from a website's Home, About and Contact pages.</p>
      </section>
      <div class="cfai-search-card">
        <form class="cfai-form">
          <label for="<?php echo esc_attr($uid); ?>-url">Website URL</label>
          <div class="cfai-input-row">
            <input class="cfai-url" id="<?php echo esc_attr($uid); ?>-url" name="url" type="url" placeholder="https://example.com" autocomplete="url" required>
            <button class="cfai-submit" type="submit">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>
              <span class="cfai-button-text">Scan website</span>
            </button>
          </div>
          <div class="cfai-helper"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"></path><path d="m9 12 2 2 4-4"></path></svg> Scans up to 3 public pages. Results may vary by website.</div>
          <?php if (!is_user_logged_in()) : ?>
            <div class="cfai-guest-note" style="margin-top:12px;padding:11px 13px;border-radius:10px;background:#f5f3ff;color:#4c3ca8;font-size:12px;line-height:1.6">Free guest access: scan one website. <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" style="font-weight:700;color:#4c3ca8;text-decoration:underline">Log in or register</a> to continue scanning.</div>
          <?php endif; ?>
        </form>
        <div class="cfai-loading" aria-live="polite"><span class="cfai-spinner"></span><span class="cfai-loading-text">Scanning website pages…</span></div>
        <p class="cfai-status" role="status" aria-live="polite"></p>
      </div>
      <div class="cfai-results" aria-live="polite"></div>
    </div>
    <script>
    (() => {
      const script = document.currentScript;
      const app = script && script.previousElementSibling;
      if (!app || app.dataset.ready) return;
      app.dataset.ready = '1';
      const form = app.querySelector('.cfai-form');
      const input = app.querySelector('.cfai-url');
      const button = app.querySelector('.cfai-submit');
      const buttonText = app.querySelector('.cfai-button-text');
      const status = app.querySelector('.cfai-status');
      const loading = app.querySelector('.cfai-loading');
      const loadingText = app.querySelector('.cfai-loading-text');
      const results = app.querySelector('.cfai-results');
      const endpoint = <?php echo wp_json_encode($endpoint); ?>;
      const nonce = <?php echo wp_json_encode($nonce); ?>;

      const icons = {
        email: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m3 7 9 6 9-6"></path></svg>',
        phone: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2A19.8 19.8 0 0 1 11.2 19a19.4 19.4 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7l.5 3a2 2 0 0 1-.6 1.8L7.2 10.3a16 16 0 0 0 6 6l1.8-1.8a2 2 0 0 1 1.8-.6l3 .5a2 2 0 0 1 2.2 2.5Z"></path></svg>',
        social: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"></path></svg>',
        copy: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="8" width="13" height="13" rx="2"></rect><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"></path></svg>'
      };
      function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
      }
      function safeHttpUrl(value) {
        try { const u = new URL(value); return ['http:', 'https:'].includes(u.protocol) ? u.href : ''; }
        catch (_) { return ''; }
      }
      function makeStat(number, label, icon) {
        const card = el('div', 'cfai-stat');
        const iconBox = el('span', 'cfai-stat-icon'); iconBox.innerHTML = icon;
        const copy = el('div', 'cfai-stat-copy');
        copy.append(el('strong', 'cfai-stat-number', String(number)));
        copy.append(el('span', 'cfai-stat-label', label));
        card.append(iconBox, copy);
        return card;
      }
      function makeSection(title, items, icon, kind, wide) {
        const card = el('section', 'cfai-result-card' + (wide ? ' cfai-social-card' : ''));
        const head = el('div', 'cfai-card-head');
        const titleWrap = el('div', 'cfai-card-title');
        const iconBox = el('span', 'cfai-card-mini-icon'); iconBox.innerHTML = icon;
        titleWrap.append(iconBox, el('h4', '', title));
        head.append(titleWrap, el('span', 'cfai-count', String(items.length)));
        card.append(head);
        if (!items.length) {
          const empty = el('div', 'cfai-empty');
          empty.append(el('strong', '', 'No results found yet'), el('span', '', 'Try another website or check whether its contact details are public.'));
          card.append(empty); return card;
        }
        const list = el('ul', 'cfai-item-list');
        items.forEach(item => {
          const value = String(item && item.value || '').trim();
          if (!value) return;
          const li = el('li', 'cfai-item');
          const main = el('div', 'cfai-item-main');
          let displayed;
          if (kind === 'email' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
            displayed = el('a', 'cfai-value', value); displayed.href = 'mailto:' + value;
          } else if (kind === 'phone' && /^[+\d\s().\-/]+$/.test(value) && value.replace(/\D/g,'').length >= 7) {
            displayed = el('a', 'cfai-value', value); displayed.href = 'tel:' + value.replace(/[^+\d]/g,'');
          } else if (kind === 'social' && safeHttpUrl(value)) {
            displayed = el('a', 'cfai-value', value); displayed.href = safeHttpUrl(value); displayed.target = '_blank'; displayed.rel = 'noopener noreferrer';
          } else { displayed = el('span', 'cfai-value', value); }
          main.append(displayed);
          const source = safeHttpUrl(item.source || '');
          if (source) {
            const sourceLine = el('small', 'cfai-source');
            sourceLine.append(document.createTextNode('Found on '));
            const sourceLink = el('a', '', (() => { try { return new URL(source).hostname + (new URL(source).pathname === '/' ? '' : new URL(source).pathname); } catch (_) { return source; } })());
            sourceLink.href = source; sourceLink.target = '_blank'; sourceLink.rel = 'noopener noreferrer';
            sourceLine.append(sourceLink); main.append(sourceLine);
          }
          const copy = el('button', 'cfai-copy'); copy.type = 'button'; copy.innerHTML = icons.copy; copy.append(document.createTextNode(' Copy')); copy.setAttribute('aria-label', 'Copy ' + value);
          copy.addEventListener('click', async () => {
            try { await navigator.clipboard.writeText(value); copy.textContent = 'Copied'; setTimeout(() => { copy.innerHTML = icons.copy; copy.append(document.createTextNode(' Copy')); }, 1200); }
            catch (_) { copy.textContent = 'Select text'; }
          });
          li.append(main, copy); list.append(li);
        });
        card.append(list); return card;
      }
      function render(data) {
        results.replaceChildren();
        const emails = Array.isArray(data.emails) ? data.emails : [];
        const phones = Array.isArray(data.phones) ? data.phones : [];
        const socials = Array.isArray(data.social_links) ? data.social_links : [];
        const top = el('div', 'cfai-results-top');
        top.append(el('h3', '', 'Scan results'));
        const pill = el('span', 'cfai-pages-pill', '✓ ' + Number(data.pages_scanned || 0) + ' pages scanned');
        top.append(pill); results.append(top);
        const stats = el('div', 'cfai-stats');
        stats.append(makeStat(emails.length, 'Email addresses', icons.email), makeStat(phones.length, 'Phone numbers', icons.phone), makeStat(socials.length, 'Social profiles', icons.social));
        results.append(stats);
        const grid = el('div', 'cfai-result-grid');
        grid.append(makeSection('Email addresses', emails, icons.email, 'email', false));
        grid.append(makeSection('Phone numbers', phones, icons.phone, 'phone', false));
        grid.append(makeSection('Social profiles', socials, icons.social, 'social', true));
        results.append(grid);
        const pages = Array.isArray(data.scanned_pages) ? data.scanned_pages : [];
        if (pages.length) {
          const pageBox = el('div', 'cfai-scanned-pages'); pageBox.append(el('h4', '', 'Pages checked'));
          pages.forEach(page => { const safe = safeHttpUrl(page); if (safe) { const a = el('a', 'cfai-page-chip', safe); a.href = safe; a.target = '_blank'; a.rel = 'noopener noreferrer'; pageBox.append(a); } });
          results.append(pageBox);
        }
      }
      form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!input.value.trim()) return;
        status.textContent = ''; status.dataset.kind = '';
        results.replaceChildren(); loading.classList.add('is-visible'); loadingText.textContent = 'Scanning Home, About and Contact pages…';
        button.disabled = true; buttonText.textContent = 'Scanning…';
        try {
          const response = await fetch(endpoint, {method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify({url:input.value.trim()})});
          const data = await response.json();
          if (!response.ok || !data.success) throw new Error(data.message || 'Scan failed. Please try again.');
          render(data);
          status.textContent = 'Scan complete. Review the results below.'; status.dataset.kind = 'success';
        } catch (error) {
          status.textContent = error.message || 'Something went wrong. Please try again.'; status.dataset.kind = 'error';
        } finally {
          loading.classList.remove('is-visible'); button.disabled = false; buttonText.textContent = 'Scan website';
        }
      });
    })();
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode('contactfinder_scanner', 'cfai_scanner_shortcode');

/**
 * Shortcode: [contactfinder_dashboard]
 * Shows the current logged-in user's recent scans. New scans are saved automatically.
 */
function cfai_dashboard_shortcode() {
    if (!is_user_logged_in()) {
        return '<div class="cfai-dashboard-login">Please log in to view your scan dashboard.</div>';
    }

    $history = get_user_meta(get_current_user_id(), 'cfai_scan_history', true);
    if (!is_array($history)) {
        $history = [];
    }

    ob_start();
    ?>
    <style>
      .cfai-dashboard{max-width:1100px;margin:24px auto;color:#172033;font-family:inherit}
      .cfai-dashboard-hero{padding:28px 30px;border-radius:20px;background:linear-gradient(120deg,#171d45,#5140b7 70%,#7042dc);color:#fff;box-shadow:0 16px 40px rgba(48,42,120,.16)}
      .cfai-dashboard-hero h2{color:#fff;margin:0 0 8px;font-size:clamp(25px,4vw,34px);font-weight:800}
      .cfai-dashboard-hero p{margin:0;color:#e8e9ff}
      .cfai-dashboard-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:18px 0}
      .cfai-dashboard-stat{background:#fff;border:1px solid #e2e8f5;border-radius:15px;padding:18px;box-shadow:0 5px 18px rgba(20,35,80,.04)}
      .cfai-dashboard-stat strong{display:block;font-size:27px;color:#292b73;line-height:1.3}.cfai-dashboard-stat span{font-size:13px;color:#64708b}
      .cfai-history-card{background:#fff;border:1px solid #e0e7f3;border-radius:16px;margin:14px 0;overflow:hidden;box-shadow:0 5px 20px rgba(20,35,80,.04)}
      .cfai-history-main{padding:18px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
      .cfai-history-url{font-weight:750;overflow-wrap:anywhere;color:#252b5f}.cfai-history-date{font-size:12px;color:#73809b;margin-top:5px}
      .cfai-history-badges{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}.cfai-history-badge{font-size:12px;background:#f2f4ff;color:#4842a4;border-radius:999px;padding:5px 9px}
      .cfai-history-toggle{display:inline-block;background:#5c4ce2;color:#fff!important;border-radius:10px;padding:10px 14px;font-weight:700;text-decoration:none!important;cursor:pointer;border:0}
      .cfai-history-details{border-top:1px solid #e8edf6;padding:18px 20px;background:#fbfcff}
      .cfai-history-details h4{font-size:15px;margin:12px 0 7px}.cfai-history-list{margin:0;padding-left:20px}.cfai-history-list li{margin:6px 0;overflow-wrap:anywhere;font-size:13px}
      .cfai-history-source{display:block;color:#75809a;font-size:11px;overflow-wrap:anywhere}
      .cfai-dashboard-empty{padding:28px;text-align:center;background:#fff;border:1px dashed #cdd6e8;border-radius:16px;color:#64708b}
      @media(max-width:640px){.cfai-dashboard-stats{grid-template-columns:1fr}.cfai-dashboard-hero{padding:23px 20px}.cfai-history-main{padding:15px}}
    </style>
    <div class="cfai-dashboard">
      <div class="cfai-dashboard-hero">
        <h2>Your scan dashboard</h2>
        <p>Review recent websites you've scanned and reopen their saved contact results.</p>
      </div>
      <?php
        $total_scans = count($history);
        $total_emails = 0; $total_phones = 0; $total_socials = 0;
        foreach ($history as $record) {
            $total_emails += count($record['emails'] ?? []);
            $total_phones += count($record['phones'] ?? []);
            $total_socials += count($record['social_links'] ?? []);
        }
      ?>
      <div class="cfai-dashboard-stats">
        <div class="cfai-dashboard-stat"><strong><?php echo esc_html((string) $total_scans); ?></strong><span>Recent scans saved</span></div>
        <div class="cfai-dashboard-stat"><strong><?php echo esc_html((string) $total_emails); ?></strong><span>Email results in history</span></div>
        <div class="cfai-dashboard-stat"><strong><?php echo esc_html((string) ($total_phones + $total_socials)); ?></strong><span>Phone and social results in history</span></div>
      </div>

      <?php if (empty($history)) : ?>
        <div class="cfai-dashboard-empty"><strong>No scan history yet</strong><br>Run a scan on the ContactFinder AI page. Your next successful scan will appear here automatically.</div>
      <?php else : ?>
        <?php foreach ($history as $index => $record) :
            $record_url = esc_url($record['website_url'] ?? '');
            $record_date = isset($record['scanned_at']) ? $record['scanned_at'] : '';
            $details_id = 'cfai-history-' . absint($index);
        ?>
          <article class="cfai-history-card">
            <div class="cfai-history-main">
              <div>
                <div class="cfai-history-url"><?php echo esc_html($record['website_url'] ?? 'Website scan'); ?></div>
                <div class="cfai-history-date"><?php echo esc_html($record_date); ?></div>
                <div class="cfai-history-badges">
                  <span class="cfai-history-badge"><?php echo esc_html((string) count($record['emails'] ?? [])); ?> emails</span>
                  <span class="cfai-history-badge"><?php echo esc_html((string) count($record['phones'] ?? [])); ?> phones</span>
                  <span class="cfai-history-badge"><?php echo esc_html((string) count($record['social_links'] ?? [])); ?> social links</span>
                  <span class="cfai-history-badge"><?php echo esc_html((string) ($record['pages_scanned'] ?? 0)); ?> pages</span>
                </div>
              </div>
              <button class="cfai-history-toggle" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr($details_id); ?>" onclick="var p=document.getElementById('<?php echo esc_js($details_id); ?>');var o=this.getAttribute('aria-expanded')==='true';p.hidden=o;this.setAttribute('aria-expanded',o?'false':'true');this.textContent=o?'View results':'Hide results';">View results</button>
            </div>
            <div class="cfai-history-details" id="<?php echo esc_attr($details_id); ?>" hidden>
              <?php foreach (['emails' => 'Email addresses', 'phones' => 'Phone numbers', 'social_links' => 'Social profiles'] as $key => $label) : ?>
                <h4><?php echo esc_html($label); ?></h4>
                <?php if (empty($record[$key])) : ?>
                  <p>No results saved.</p>
                <?php else : ?>
                  <ul class="cfai-history-list">
                    <?php foreach ($record[$key] as $item) : ?>
                      <li>
                        <?php if ($key === 'emails' && !empty($item['value'])) : ?>
                          <a href="mailto:<?php echo esc_attr($item['value']); ?>"><?php echo esc_html($item['value']); ?></a>
                        <?php elseif ($key === 'social_links' && !empty($item['value'])) : ?>
                          <a href="<?php echo esc_url($item['value']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($item['value']); ?></a>
                        <?php else : ?>
                          <?php echo esc_html($item['value'] ?? ''); ?>
                        <?php endif; ?>
                        <?php if (!empty($item['source'])) : ?><span class="cfai-history-source">Source: <?php echo esc_html($item['source']); ?></span><?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              <?php endforeach; ?>
              <?php if (!empty($record['scanned_pages']) && is_array($record['scanned_pages'])) : ?>
                <h4>Pages scanned</h4><ul class="cfai-history-list"><?php foreach ($record['scanned_pages'] as $page) : ?><li><a href="<?php echo esc_url($page); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($page); ?></a></li><?php endforeach; ?></ul>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
        <p style="font-size:12px;color:#73809b">For privacy and storage limits, only the 20 most recent scans are kept per user. Scans made before scan history was enabled will not appear.</p>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('contactfinder_dashboard', 'cfai_dashboard_shortcode');



/**
 * Admin-only overview of users' saved ContactFinder scans.
 */
function cfai_register_admin_page() {
    add_menu_page(
        'ContactFinder Scans',
        'ContactFinder Scans',
        'manage_options',
        'cfai-user-scans',
        'cfai_render_admin_page',
        'dashicons-search',
        58
    );
}
add_action('admin_menu', 'cfai_register_admin_page');

function cfai_render_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to view this page.', 'contactfinder-ai'));
    }
    $users = get_users([
        'fields' => ['ID', 'display_name', 'user_email', 'user_login'],
        'number' => 200,
        'orderby' => 'registered',
        'order' => 'DESC',
    ]);
    echo '<div class="wrap"><h1>ContactFinder AI — User Scans</h1>';
    echo '<p>Shows saved scans for up to 200 registered users. Each user history stores up to 20 recent scans.</p>';
    echo '<table class="widefat striped"><thead><tr><th>User</th><th>Email</th><th>Saved scans</th><th>Recent website scans</th></tr></thead><tbody>';
    $found = false;
    foreach ($users as $user) {
        $history = get_user_meta($user->ID, 'cfai_scan_history', true);
        if (!is_array($history) || !$history) continue;
        $found = true;
        echo '<tr><td>' . esc_html($user->display_name . ' (@' . $user->user_login . ')') . '</td>';
        echo '<td><a href="mailto:' . esc_attr($user->user_email) . '">' . esc_html($user->user_email) . '</a></td>';
        echo '<td>' . esc_html((string) count($history)) . '</td><td><details><summary>View saved scans</summary><ul style="margin:10px 0 0 18px">';
        foreach ($history as $record) {
            $site = esc_url($record['website_url'] ?? '');
            $date = sanitize_text_field($record['scanned_at'] ?? '');
            $email_count = count($record['emails'] ?? []);
            $phone_count = count($record['phones'] ?? []);
            $social_count = count($record['social_links'] ?? []);
            echo '<li style="margin-bottom:10px">';
            if ($site) echo '<a href="' . $site . '" target="_blank" rel="noopener noreferrer">' . esc_html($site) . '</a>';
            echo ' — ' . esc_html($date) . '<br><small>' . esc_html((string) $email_count) . ' emails · ' . esc_html((string) $phone_count) . ' phones · ' . esc_html((string) $social_count) . ' social links</small>';
            foreach (['emails' => 'Emails', 'phones' => 'Phones', 'social_links' => 'Social profiles'] as $key => $label) {
                if (empty($record[$key]) || !is_array($record[$key])) continue;
                echo '<details style="margin-top:4px"><summary>' . esc_html($label) . '</summary><ul>';
                foreach ($record[$key] as $item) {
                    $value = sanitize_text_field($item['value'] ?? '');
                    if ($value !== '') echo '<li>' . esc_html($value) . '</li>';
                }
                echo '</ul></details>';
            }
            echo '</li>';
        }
        echo '</ul></details></td></tr>';
    }
    if (!$found) echo '<tr><td colspan="4">No saved user scans found yet.</td></tr>';
    echo '</tbody></table></div>';
}
