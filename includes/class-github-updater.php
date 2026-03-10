<?php
/**
 * GitHub-baseret auto-updater for ProductFeed plugin.
 *
 * Tjekker GitHub releases for nye versioner og tillader
 * opdatering direkte fra WordPress admin.
 */

defined('ABSPATH') || exit;

class PF_GitHub_Updater {

    private string $slug;
    private string $plugin_file;
    private string $github_repo;
    private string $plugin_basename;
    private ?object $github_response = null;

    public function __construct(string $plugin_file, string $github_repo) {
        $this->plugin_file    = $plugin_file;
        $this->github_repo    = $github_repo;
        $this->slug           = dirname(plugin_basename($plugin_file));
        $this->plugin_basename = plugin_basename($plugin_file);

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
    }

    /**
     * Hent seneste release fra GitHub API.
     */
    private function get_github_release(): ?object {
        if ($this->github_response !== null) {
            return $this->github_response;
        }

        $transient_key = 'pf_github_release_' . md5($this->github_repo);
        $cached = get_transient($transient_key);

        if ($cached !== false) {
            $this->github_response = $cached;
            return $this->github_response;
        }

        $url = "https://api.github.com/repos/{$this->github_repo}/releases/latest";
        $response = wp_remote_get($url, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $this->github_response = null;
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response));

        if (empty($body->tag_name)) {
            return null;
        }

        $this->github_response = $body;

        // Cache i 6 timer
        set_transient($transient_key, $body, 6 * HOUR_IN_SECONDS);

        return $this->github_response;
    }

    /**
     * Fjern "v" prefix fra version tag (v1.7.0 → 1.7.0).
     */
    private function parse_version(string $tag): string {
        return ltrim($tag, 'vV');
    }

    /**
     * Find download URL fra release assets eller brug zipball.
     */
    private function get_download_url(object $release): string {
        // Prioriter en .zip asset hvis den findes
        if (!empty($release->assets)) {
            foreach ($release->assets as $asset) {
                if (str_ends_with($asset->name, '.zip')) {
                    return $asset->browser_download_url;
                }
            }
        }

        // Fallback til GitHub zipball
        return $release->zipball_url;
    }

    /**
     * Tjek om der er en ny version tilgængelig.
     */
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_github_release();

        if ($release === null) {
            return $transient;
        }

        $remote_version  = $this->parse_version($release->tag_name);
        $current_version = $transient->checked[$this->plugin_basename] ?? PF_VERSION;

        if (version_compare($remote_version, $current_version, '>')) {
            $transient->response[$this->plugin_basename] = (object) [
                'slug'        => $this->slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $remote_version,
                'url'         => "https://github.com/{$this->github_repo}",
                'package'     => $this->get_download_url($release),
                'icons'       => [],
                'banners'     => [],
                'tested'      => get_bloginfo('version'),
                'requires'    => '6.0',
                'requires_php'=> '8.0',
            ];
        }

        return $transient;
    }

    /**
     * Vis plugin info i WordPress "View details" popup.
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== $this->slug) {
            return $result;
        }

        $release = $this->get_github_release();

        if ($release === null) {
            return $result;
        }

        $plugin_data = get_plugin_data($this->plugin_file);

        return (object) [
            'name'            => $plugin_data['Name'],
            'slug'            => $this->slug,
            'version'         => $this->parse_version($release->tag_name),
            'author'          => $plugin_data['Author'],
            'homepage'        => "https://github.com/{$this->github_repo}",
            'requires'        => '6.0',
            'requires_php'    => '8.0',
            'tested'          => get_bloginfo('version'),
            'download_link'   => $this->get_download_url($release),
            'sections'        => [
                'description'  => $plugin_data['Description'],
                'changelog'    => nl2br(esc_html($release->body ?? 'Ingen changelog tilgængelig.')),
            ],
            'last_updated'    => $release->published_at ?? '',
        ];
    }

    /**
     * Ret mappe-navn efter installation (GitHub zipball har repo-navn som mappe).
     */
    public function after_install($response, $hook_extra, $result) {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $result;
        }

        global $wp_filesystem;

        $install_dir = plugin_dir_path($this->plugin_file);
        $wp_filesystem->move($result['destination'], $install_dir);
        $result['destination'] = $install_dir;

        activate_plugin($this->plugin_basename);

        return $result;
    }
}
