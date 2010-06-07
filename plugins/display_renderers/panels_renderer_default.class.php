<?php

/**
 * The default render pipeline for a Panels display object.
 *
 * Given a fully-loaded panels_display object, this class will turn its
 * combination of layout, panes, and styles into HTML, invoking caching
 * appropriately along the way. Interacting with the renderer externally is
 * very simple - just pass it the display object and call the render() method:
 *
 * @code
 *   // given that $display is a fully loaded Panels display object
 *   $renderer = new panels_renderer_default();
 *   $renderer->build($display);
 *   $html_output = $renderer->render();
 * @endcode
 *
 * Internally, the render pipeline is divided into two phases, prepare and
 * render:
 *   - The prepare phase transforms the skeletal data on the provided
 *     display object into a structure that is expected by the render phase.
 *     It is divided into a series of discrete sub-methods and operates
 *     primarily by passing parameters, all with the intention of making
 *     subclassing easier.
 *   - The render phase relies primarily on data stored in the renderer object's
 *     properties, presumably set in the prepare phase. It iterates through the
 *     rendering of each pane, pane styling, placement in panel regions, region
 *     styling, and finally the arrangement of rendered regions in the layout.
 *     Caching, if in use, is triggered per pane, or on the entire display.
 *
 * In short: prepare builds conf, render renders conf. Subclasses should respect
 * this separation of responsibilities by adhering to these loose guidelines,
 * given a loaded display object:
 *   - If your renderer needs to modify the datastructure representing what is
 *     to be rendered (panes and their conf, styles, caching, etc.), it should
 *     use the prepare phase.
 *   - If your renderer needs to modify the manner in which that renderable
 *     datastructure data is rendered, it should use the render phase.
 *
 * In the vast majority of use cases, this default renderer will be sufficient
 * and need not be switched out/subclassed; style plugins and/or layout plugins
 * accomplish everything needed. If you think you might need a custom
 * renderer, consider the following criteria/examples:
 *   - Some additional markup needs to be added to EVERY SINGLE panel.
 *   - Given a full display object, just render one pane.
 *   - Show a Panels admin interface.
 *
 * The system is almost functionally identical to the old procedural approach,
 * with some exceptions (@see panels_renderer_legacy for details). The approach
 * here differs primarily in its friendliness to tweaking via inheritance.
 */
class panels_renderer_default {
  /**
   *
   * @var panels_display
   */
  var $display;
  var $plugins = array();

  /**
   * A multilevel array of rendered data. The first level of the array
   * indicates the type of rendered data, typically with up to three keys:
   * 'layout', 'regions', and 'panes'. The relevant rendered data is stored as
   * the value for each of these keys as it is generated:
   *  - 'panes' are an associative array of rendered output, keyed on pane id.
   *  - 'regions' are an associative array of rendered output, keyed on region
   *    name.
   *  - 'layout' is the whole of the rendered output.
   *
   * @var array
   */
  var $rendered = array();

  /**
   * A multilevel array of data prepared for rendering. The first level of the
   * array indicates the type of prepared data. The standard renderer populates
   * and uses two top-level keys, 'panes' and 'regions':
   *  - 'panes' are an associative array of pane objects to be rendered, keyed
   *    on pane id and sorted into proper rendering order.
   *  - 'regions' are an associative array of regions, keyed on region name,
   *    each of which is itself an indexed array of pane ids in the order in
   *    which those panes appear in that region.
   *
   * @var array
   */
  var $prepared = array();

  /**
   * Boolean state variable, indicating whether or not the prepare() method has
   * been run.
   *
   * This state is checked in panels_renderer_default::render_layouts() to
   * determine whether the prepare method should be automatically triggered.
   * @var bool
   */
  var $prep_run = FALSE;

  /**
   * Receive and store the display object to be rendered.
   *
   * This is a psuedo-constructor that should typically be called immediately
   * after object construction.
   *
   * @param panels_display $display
   *   The panels display object to be rendered.
   */
  function build(&$display) {
    $layout = panels_get_layout($display->layout);
    $this->display = &$display;
    $this->plugins['layout'] = $layout;
    if (!isset($layout['panels'])) {
      $this->plugins['layout']['panels'] = panels_get_panels($layout, $display);
    }
  }

  /**
   * Prepare the attached display for rendering.
   *
   * This is the outermost prepare method. It calls several sub-methods as part
   * of the overall preparation process. This compartmentalization is intended
   * to ease the task of modifying renderer behavior in child classes.
   *
   * If you override this method, it is important that you either call this
   * method via parent::prepare(), or manually set $this->prep_run = TRUE.
   *
   * @param mixed $external_settings
   *  An optional parameter allowing external code to pass in additional
   *  settings for use in the preparation process. Not used in the default
   *  renderer, but included for interface consistency.
   */
  function prepare($external_settings = NULL) {
    $this->prepare_panes($this->display->content);
    $this->prepare_regions($this->display->panels, $this->display->panel_settings);
    $this->prepared['empty regions'] = array_diff(array_keys($this->plugins['layout']['panels']), array_keys($this->prepared['regions']));
    $this->prep_run = TRUE;
  }

  /**
   * Prepare the list of panes to be rendered, accounting for visibility/access
   * settings and rendering order.
   *
   * This method represents the standard approach for determining the list of
   * panes to be rendered that is compatible with all parts of the Panels
   * architecture. It first applies visibility & access checks, then sorts panes
   * into their proper rendering order, and returns the result as an array.
   *
   * Inheriting classes should override this method if that renderer needs to
   * regularly make additions to the set of panes that will be rendered.
   *
   * @param array $panes
   *  An associative array of panes, keyed on pane id.
   * @return array
   *  An associative array of panes to be rendered, keyed on pane id and sorted
   *  into proper rendering order.
   */
  function prepare_panes($panes) {
    ctools_include('content');
    // Use local variables as writing to them is very slightly faster
    $normal = $last = array();

    // Prepare the list of panes to be rendered
    foreach ($panes as $pid => $pane) {
      // TODO remove in 7.x and ensure the upgrade path weeds out any stragglers; it's been long enough
      $pane->shown = !empty($pane->shown); // guarantee this field exists.
      // If this pane is not visible to the user, skip out and do the next one
      if (!$pane->shown || !panels_pane_access($pane, $this->display)) {
        continue;
      }

      $ct_plugin_def = ctools_get_content_type($pane->type);

      // If this pane wants to render last, add it to the $last array. We allow
      // this because some panes need to be rendered after other panes,
      // primarily so they can do things like the leftovers of forms.
      if (!empty($ct_plugin_def['render last'])) {
        $last[$pid] = $pane;
      }
      // Otherwise, render it in the normal order.
      else {
        $normal[$pid] = $pane;
      }
    }
    $this->prepared['panes'] = $normal + $last;
    return $this->prepared['panes'];
  }

  /**
   * @param array $regions
   * @param array $settings
   */
  function prepare_regions($region_list, $settings) {
    // Initialize defaults to be used for regions without their own explicit
    // settings. Use display settings if they exist, else hardcoded defaults
    $default = array(
      'style' => !empty($settings['style']) ? $settings['style'] : panels_get_style('default'),
      'style settings' => isset($settings['style_settings']['default']) ? $settings['style_settings']['default'] : array(),
    );

    $regions = array();
    if (empty($settings)) {
      // No display/panel region settings exist, init all with the defaults.
      foreach ($region_list as $region_id => $panes) {
        $regions[$region_id] = $default;
        $regions[$region_id]['pids'] = $panes;
      }
    }
    else {
      // Some settings exist; iterate through each region and set individually
      foreach ($region_list as $region_id => $panes) {
        if (empty($settings[$region_id]['style']) || $settings[$region_id]['style'] == -1) {
          $regions[$region_id] = $default;
        }
        else {
          $regions[$region_id]['style'] = panels_get_style($settings[$region_id]['style']);
          $regions[$region_id]['style settings'] = isset($settings['style_settings'][$region_id]) ? $settings['style_settings'][$region_id] : array();
        }
        $regions[$region_id]['pids'] = $panes;
      }
    }
    $this->prepared['regions'] = $regions;
    return $this->prepared['regions'];
  }

  /**
   * Build inner content, then hand off to layout-specified theme function for
   * final render step.
   *
   * This is the outermost method in the Panels render pipeline. It calls the
   * inner methods, which return a content array, which is in turn passed to the
   * theme function specified in the layout plugin.
   *
   * @return string
   *  Themed & rendered HTML output.
   */
  function render() {
    // Attach out-of-band data first.
    $this->add_meta();

    if (empty($this->display->cache['method'])) {
      return $this->render_layout();
    }
    else {
      $cache = panels_get_cached_content($this->display, $this->display->args, $this->display->context);
      if ($cache === FALSE) {
        $cache = new panels_cache_object();
        $cache->set_content($this->render_layout());
        panels_set_cached_content($cache, $this->display, $this->display->args, $this->display->context);
      }
      return $cache->content;
    }
  }

  /**
   * Perform display/layout-level render operations.
   *
   * This method triggers all the inner pane/region rendering processes, passes
   * that to the layout plugin's theme callback, and returns the rendered HTML.
   *
   * If display-level caching is enabled and that cache is warm, this method
   * will not be called.
   */
  function render_layout() {
    if (empty($this->prep_run)) {
      $this->prepare();
    }
    $this->render_panes();
    $this->render_regions();
    $this->rendered['layout'] = theme($this->plugins['layout']['theme'], check_plain($this->display->css_id), $this->rendered['regions'], $this->display->layout_settings, $this->display);
    return $this->rendered['layout'];
  }

  /**
   * Attach out-of-band page metadata (e.g., CSS and JS).
   *
   * This must be done before render, because panels-within-panels must have
   * their CSS added in the right order: inner content before outer content.
   */
  function add_meta() {
    if (!empty($this->plugins['layout']['css'])) {
      if (file_exists(path_to_theme() . '/' . $this->plugins['layout']['css'])) {
        drupal_add_css(path_to_theme() . '/' . $this->plugins['layout']['css']);
      }
      else {
        drupal_add_css($this->plugins['layout']['path'] . '/' . $this->plugins['layout']['css']);
      }
    }
  }

  function render_panes() {
    ctools_include('content');

    // First, render all the panes into little boxes.
    $this->rendered['panes'] = array();
    foreach ($this->prepared['panes'] as $pid => $pane) {
      $this->rendered['panes'][$pid] = $this->render_pane($pane);
    }
  }

  /**
   * Render a pane using the appropriate style.
   *
   * @param object $pane
   *  The $pane information from the display
   */
  function render_pane($pane) {
    $content = $this->render_pane_content($pane);
    if ($this->display->hide_title == PANELS_TITLE_PANE && !empty($this->display->title_pane) && $this->display->title_pane == $pane->pid) {

      // If the user selected to override the title with nothing, and selected
      // this as the title pane, assume the user actually wanted the original
      // title to bubble up to the top but not actually be used on the pane.
      if (empty($content->title) && !empty($content->original_title)) {
        $this->display->stored_pane_title = $content->original_title;
      }
      else {
        $this->display->stored_pane_title = !empty($content->title) ? $content->title : '';
      }
    }

    if (!empty($pane->style['style'])) {
      $style = panels_get_style($pane->style['style']);

      if (isset($style) && isset($style['render pane'])) {
        $output = theme($style['render pane'], $content, $pane, $this->display);

        // This could be null if no theme function existed.
        if (isset($output)) {
          return $output;
        }
      }
    }

    if (!empty($content->content)) {
      // fallback
      return theme('panels_pane', $content, $pane, $this->display);
    }
  }

  /**
   * Render the contents of a single pane.
   *
   * This method retrieves pane content and produces a ready-to-render content
   * object. It also manages pane-specific caching.
   *
   * @param stdClass $pane
   *  A Panels pane object, as loaded from the database.
   */
  function render_pane_content($pane) { // TODO remove this method by collapsing it into $this->render_panes()
    ctools_include('context');
    // TODO finally safe to remove this check?
    if (!is_array($this->display->context)) {
      watchdog('panels', 'renderer:render_pane_content() hit with a non-array for the context', $this->display, WATCHDOG_DEBUG);
      $this->display->context = array();
    }

    $content = FALSE;
    $caching = !empty($pane->cache['method']) ? TRUE : FALSE;
    if ($caching && ($cache = panels_get_cached_content($this->display, $this->display->args, $this->display->context, $pane))) {
      $content = $cache->content;
    }
    else {
      $content = ctools_content_render($pane->type, $pane->subtype, $pane->configuration, array(), $this->display->args, $this->display->context);
      foreach (module_implements('panels_pane_content_alter') as $module) {
        $function = $module . '_panels_pane_content_alter';
        $function($content, $pane, $this->display->args, $this->display->context);
      }
      if ($caching) {
        $cache = new panels_cache_object();
        $cache->set_content($content);
        panels_set_cached_content($cache, $this->display, $this->display->args, $this->display->context, $pane);
        $content = $cache->content;
      }
    }

    // Pass long the css_id that is usually available.
    if (!empty($pane->css['css_id'])) {
      $content->css_id = $pane->css['css_id'];
    }

    // Pass long the css_class that is usually available.
    if (!empty($pane->css['css_class'])) {
      $content->css_class = $pane->css['css_class'];
    }

    return $content;
  }

  /**
   * Render all panes in the attached display into their panel regions, then
   * render those regions.
   *
   * @return array
   *   An array of rendered panel regions, keyed on the region name.
   */
  function render_regions() {
    // Initialize the regions array with keys for each region and NULL values
    // for each; this prevents warnings on empty regions later
    $this->rendered['regions'] = array();
    foreach (array_keys($this->plugins['layout']['panels']) as $region_id) {
      $this->rendered['regions'][$region_id] = NULL;
    }

    // Loop through all panel regions, put all panes that belong to the current
    // region in an array, then render the region. Primarily this ensures that
    // the panes are arranged in the proper order.
    $content = array();
    foreach ($this->prepared['regions'] as $region_id => $conf) {
      $region_panes = array();
      foreach ($conf['pids'] as $pid) {
        // Only include panes for region rendering if they had some output.
        if (!empty($this->rendered['panes'][$pid])) {
          $region_panes[$pid] = $this->rendered['panes'][$pid];
        }
      }
      $this->rendered['regions'][$region_id] = $this->render_region($region_id, $region_panes);
    }

    return $this->rendered['regions'];
  }

  /**
   * Render a single panel region.
   *
   * Primarily just a passthrough to the panel region rendering callback
   * specified by the style plugin that is attached to the current panel region.
   *
   * @param $region_id
   *   The ID of the panel region being rendered
   * @param $panes
   *   An array of panes that are assigned to the panel that's being rendered.
   *
   * @return string
   *   The rendered, HTML string output of the passed-in panel region.
   */
  function render_region($region_id, $panes) {
    $style = $this->prepared['regions'][$region_id]['style'];
    $style_settings = $this->prepared['regions'][$region_id]['style settings'];

    // Retrieve the pid (can be a panel page id, a mini panel id, etc.), this
    // might be used (or even necessary) for some panel display styles.
    // TODO: Got to fix this to use panel page name instead of pid, since pid is
    // no longer guaranteed. This needs an API to be able to set the final id.
    $owner_id = 0;
    if (isset($this->display->owner) && is_object($this->display->owner) && isset($this->display->owner->id)) {
      $owner_id = $this->display->owner->id;
    }

    return theme($style['render panel'], $this->display, $owner_id, $panes, $style_settings, $region_id);
  }
}