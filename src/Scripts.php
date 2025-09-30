<?php

/**
 * Plugin Name: Jolt Vite WordPress
 * Plugin URI: https://joltdesign.co.uk/
 * Description: Integrate Vite with WordPress
 * Author: Jolt Design
 * Author URI: https://joltdesign.co.uk/
 * Version: 1.0.0
 * License: Private
 *
 * @package Jolt\ViteWP
 */

namespace Jolt\ViteWP;

class Scripts
{
  const MARKER_STALE_SECONDS = 120;

  protected string $key;
  protected string $entryPoint;
  protected string $codeDir;
  protected string $distUrl;
  protected string $baseUrl = '/';
  protected string $devServerUrl;
  protected bool $devMode = false;
  protected bool $react = false;
  protected bool $preloadEnabled = true;
  protected $markerData;
  protected $manifest;
  protected $enqueued = [];
  protected $preloadChunks = [];

  /**
   * Create a script enqueuer for the given files
   *
   * @param string $codeDir the relative path to the assets with no trailing slash, e.g. "src"
   * @param string $distUrl the URL the assets are served at, e.g. "/wp-content/plugins/example/dist/"
   * @param string $entryPoint the entrypoint to enqueue scripts for, e.g. "src/index.js"
   */
  public function __construct(string $codeDir, string $distUrl, string $entryPoint)
  {
    $this->codeDir = $codeDir;
    $this->distUrl = $distUrl;
    $this->entryPoint = $entryPoint;
    $this->key = 'jolt_vite_' . $this->hash($codeDir);

    $marker = $this->getMarkerData();

    if ($marker && !$this->isMarkerStale($marker)) {
      $this->devServerUrl = "http://localhost:{$marker->serverPort}";
    }
  }

  /**
   * Enable or disable dev mode
   *
   * Dev mode enables the check to see if the Vite dev server is running and swaps in the HMR scripts.
   *
   * @param bool $enabled
   */
  public function setDevMode(bool $enabled)
  {
    $this->devMode = $enabled;
  }

  /**
   * Enable or disable React mode
   *
   * React mode will inject extra JS in the head tag to enable HMR for React
   *
   * @param bool $enabled
   */
  public function setReact(bool $enabled)
  {
    $this->react = $enabled;
  }

  /**
   * Enable or disable module preloading
   *
   * Module preloading will insert
   *
   * @param bool $enabled
   */
  public function setPreload(bool $enabled)
  {
    $this->preloadEnabled = $enabled;
  }

  /**
   * Override the base URL for the dev server
   *
   * Useful if your scripts are only enqueued in a sub-directory.
   *
   * @param string $url
   */
  public function setBaseUrl(string $url)
  {
    $this->baseUrl = $url;
  }

  /**
   * Enqueue the styles and scripts
   *
   * Call this in the `wp_enqueue_scripts` action hook.
   */
  public function enqueue()
  {
    if ($this->shouldEnqueueDevScripts()) {
      wp_enqueue_script_module("{$this->key}_vite_client", $this->getDevUrl('@vite/client'), [], null, ['in_footer' => true]);
      wp_enqueue_script_module("{$this->key}_entrypoint", $this->getDevUrl($this->entryPoint), [], null, ['in_footer' => true]);
      add_action('wp_head', [$this, 'outputReactDevHmrScripts']);
    } else {
      $this->manifest = json_decode(file_get_contents($this->getPath('/dist/.vite/manifest.json')));
      $this->enqueueDependencies($this->entryPoint);
      add_action('wp_head', [$this, 'outputPreloadChunks']);
    }
  }

  /**
   * Echoes the React HMR script if both dev mode and React mode are enabled
   *
   * @internal needs to be public for WordPress to access it as a callback
   */
  public function outputReactDevHmrScripts()
  {
    if ($this->devMode && $this->react) {
      echo <<<EOF
          <script type="module" id="{$this->key}_react_hmr">
            import RefreshRuntime from '{$this->getDevUrl('@react-refresh')}'
            RefreshRuntime.injectIntoGlobalHook(window)
            window.\$RefreshReg\$ = () => {}
            window.\$RefreshSig\$ = () => (type) => type
            window.__vite_plugin_react_preamble_installed__ = true
          </script>
        EOF;
    }
  }

  /**
   * Outputs the module preload HTML
   *
   * @internal needs to be public for WordPress to access it as a callback
   */
  public function outputPreloadChunks()
  {
    if ($this->preloadEnabled) {
      echo implode(PHP_EOL, $this->preloadChunks);
    }
  }

  protected function getMarkerData()
  {
    if ($this->markerData === null) {
      if (file_exists($this->getPath('/.jolt-marker.tmp'))) {
        $this->markerData = json_decode(file_get_contents($this->getPath('/.jolt-marker.tmp')));
      } else {
        $this->markerData = false;
      }
    }

    return $this->markerData;
  }

  protected function isMarkerStale($marker): bool
  {
    if (!$marker || !isset($marker->lastUpdated)) {
      return false;
    }

    $markerTime = $marker->lastUpdated ? strtotime($marker->lastUpdated) : 0;
    $timeBeforeStaleMarker = time() - static::MARKER_STALE_SECONDS;

    if ($markerTime < $timeBeforeStaleMarker) {
      return true;
    }

    return false;
  }

  protected function shouldEnqueueDevScripts(): bool
  {
    return $this->devMode && $this->getMarkerData() && !$this->isMarkerStale($this->getMarkerData());
  }

  protected function getPath(string $file)
  {
    return $this->codeDir . $file;
  }

  protected function getUrl(string $path)
  {
    return $this->distUrl . $path;
  }

  protected function getDevUrl(string $path)
  {
    return $this->devServerUrl . $this->baseUrl . $path;
  }

  protected function enqueueDependencies($key, $preload = false)
  {
    if (isset($this->enqueued[$key])) {
      return;
    }

    if ($preload && !$this->preloadEnabled) {
      return;
    }

    $this->enqueued[$key] = true;
    $index = $this->manifest->{$key};
    $hash = $this->hash($key);

    if (!isset($this->preloadChunks[$key])) {
      $this->preloadChunks[$key] = $this->getPreloadLinkMarkup($this->getUrl($index->file));
    }

    if (!$preload) {
      wp_enqueue_script_module("{$this->key}_{$hash}_js", $this->getUrl($index->file), [], null, ['in_footer' => true]);
    }

    if (!empty($index->css)) {
      foreach ($index->css as $cssKey => $css) {
        wp_enqueue_style("{$this->key}_{$hash}_css_{$cssKey}", $this->getUrl($css));
      }
    }

    if (!empty($index->imports)) {
      foreach ($index->imports as $import) {
        $this->enqueueDependencies($import, $preload);
      }
    }

    if (!empty($index->dynamicImports)) {
      foreach ($index->dynamicImports as $import) {
        $this->enqueueDependencies($import, preload: true);
      }
    }
  }

  protected function hash($string): string
  {
    return substr(sha1($string), 0, 8);
  }

  protected function getPreloadLinkMarkup(string $url): string
  {
    return "<link rel=\"modulepreload\" href=\"{$url}\" />";
  }
}
