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
  protected string $key;
  protected string $entryPoint;
  protected string $codeDir;
  protected string $distUrl;
  protected string $baseUrl = '/';
  protected string $devServerUrl;
  protected bool $devMode = false;
  protected bool $react = false;
  protected $markerData;

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
    $this->key = 'jolt_vite_' . substr(sha1($codeDir), 0, 8);

    $marker = $this->getMarkerData();

    if ($marker) {
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
      $manifest = json_decode(file_get_contents($this->getPath('/dist/.vite/manifest.json')));
      $index = $manifest->{$this->entryPoint};

      wp_enqueue_script_module("{$this->key}_js", $this->getUrl($index->file), [], null, ['in_footer' => true]);

      if (!empty($index->css)) {
        foreach ($index->css as $key => $css) {
          wp_enqueue_style("{$this->key}_css_{$key}", $this->getUrl($css));
        }
      }
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

  protected function shouldEnqueueDevScripts(): bool
  {
    return $this->devMode && $this->getMarkerData();
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
}
