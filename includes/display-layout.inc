<?php
// $Id$

/**
 * @file
 *
 * Handle the forms for changing a display's layout.
 */

/**
 * Handle calling and processing of the form for editing display layouts.
 *
 * Helper function for panels_edit_layout().
 *
 * @see panels_edit_layout() for details on the various behaviors of this function.
 */
function _panels_edit_layout($display, $finish, $destination, $allowed_layouts) {
  panels_load_include('common');

  // @todo -- refactor the allowed layouts stuff for better code encapsulation.
  // module_name has been provided; the data was saved by the api_save() method.
  if (is_string($allowed_layouts)) {
    $allowed_layouts = unserialize(variable_get($allowed_layouts . "_allowed_layouts", serialize('')));
  }

  // if no parameter was provided, or the variable_get failed
  if (!$allowed_layouts) {
    // tries to load the common panels allowed layouts
    $allowed_layouts = unserialize(variable_get('panels_common_allowed_layouts', serialize('')));
    if (!$allowed_layouts) {
      // still no dice. simply creates a dummy version where all layouts
      // are allowed.
      $allowed_layouts = new panels_allowed_layouts();
      $allowed_layouts->allow_new = TRUE;
    }
  }

  // sanitize allowed layout listing; this is redundant if the
  // $allowed_layouts param was null, but the data is cached anyway
  $allowed_layouts->sync_with_available();

  $form_state = array(
    'display' => &$display,
    'finish' => $finish,
    'destination' => $destination,
    'layouts' => array_filter($allowed_layouts->allowed_layout_settings),
    're_render' => FALSE,
    'no_redirect' => TRUE,
  );

  $change_form_state = $form_state;

  $change_form = FALSE;

  // Examine $_POST to see which form they're currently using.
  if (empty($_POST) || empty($_POST['form_id']) || $_POST['form_id'] != 'panels_change_layout') {
    $output = drupal_build_form('panels_choose_layout', $form_state);
    if (empty($output)) {
      // upon submission go to next form.
      $change_form_state['layout'] = $_SESSION['layout'][$display->did] = $form_state['layout'];
      $change_form = TRUE;
    }
  }
  else {
    $change_form_state['layout'] = $_SESSION['layout'][$display->did];
    $change_form = TRUE;
  }

  if ($change_form) {
    $output = drupal_build_form('panels_change_layout', $change_form_state);
    if (empty($output)) {
      if (isset($change_form_state['back'])) {
        unset($_POST);
        return _panels_edit_layout($display, $finish, $destination, $allowed_layouts);
      }

      if (!empty($change_form_state['clicked_button']['#save-display'])) {
        drupal_set_message(t('Panel layout has been updated.'));
        panels_save_display($display);
      }

      if ($destination) {
        return drupal_goto($destination);
      }
      return $change_form_state['display'];
    }
  }
  return $output;
}

/**
 * Form definition for the display layout editor.
 *
 * @ingroup forms
 */
function panels_choose_layout(&$form_state) {
  $display = &$form_state['display'];
  $allowed_layouts = $form_state['layouts'];

  $layouts = array();
  $available_layouts = panels_get_layouts();
  foreach ($available_layouts as $layout_key => $layout_settings) {
    if (!empty($allowed_layouts[$layout_key])) {
      $layouts[$layout_key] = $layout_settings;
    }
  }
  foreach ($layouts as $id => $layout) {
    $options[$id] = panels_print_layout_icon($id, $layout, check_plain($layout['title']));
  }

  drupal_add_js(panels_get_path('js/layout.js'));
  $form['layout'] = array(
    '#type' => 'radios',
    '#title' => t('Choose layout'),
    '#options' => $options,
    '#default_value' => in_array($display->layout, array_keys($layouts)) ? $display->layout : NULL,
  );

  $form['clearer'] = array(
    // TODO: Fix this to use clear-block instead
    '#value' => '<div style="clear: both;"></div>',
  );

  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Next'),
  );

  return $form;
}

/**
 * Handle form submission of the display layout editor.
 */
function panels_choose_layout_submit($form, &$form_state) {
  $form_state['layout'] = $form_state['values']['layout'];
}

/**
 * Form definition for the display layout converter.
 *
 * This form is only triggered if the user attempts to change the layout
 * for a display that has already had content assigned to it. It allows
 * the user to select where the panes located in to-be-deleted panels should
 * be relocated to.
 *
 * @ingroup forms
 *
 * @param array $form
 *  A structured FAPI $form array.
 * @param object $display instanceof panels_display \n
 *  The panels_display object that was modified on the preceding display layout
 *  editing form.
 * @param string $new_layout_id
 *  A string containing the name of the layout the display is to be converted to.
 *  These strings correspond exactly to the filenames of the *.inc files in panels/layouts.
 *  So, if the new layout that's been selected is the 'Two Column bricks' layout, then
 *  $new_layout_id will be 'twocol_bricks', corresponding to panels/layouts/twocol_bricks.inc.
 */
function panels_change_layout(&$form_state) {
  $display = &$form_state['display'];

  $new_layout = panels_get_layout($form_state['layout']);
  $new_layout_panels = panels_get_panels($new_layout, $display);

  $options = $new_layout_panels;
  $keys = array_keys($options);
  $default = $options[0];

  $old_layout = panels_get_layout($display->layout);

  $form['container'] = array(
    '#prefix' => '<div class="change-layout-display">',
    '#suffix' => '</div>',
  );

  $form['container']['old_layout'] = array(
    '#value' => panels_print_layout_icon($display->layout, $old_layout, check_plain($old_layout['title'])),
  );

  $form['container']['right_arrow'] = array(
    '#value' => theme('image', drupal_get_path('module', 'panels') . '/images/go-right.png'),
  );
  $form['container']['new_layout'] = array(
    '#value' => panels_print_layout_icon($form_state['layout'], $new_layout, check_plain($new_layout['title'])),
  );

  $form['container-clearer'] = array(
    // TODO: FIx this ot use clear-block instead
    '#value' => '<div style="clear: both;"></div>',
  );

  $form['old'] = array(
    '#tree' => true,
    '#prefix' => '<div class="panels-layout-list">',
    '#suffix' => '</div>',
  );

  $old_layout_panels = panels_get_panels($old_layout, $display);
  foreach ($display->panels as $id => $content) {
    $form['old'][$id] = array(
      '#type' => 'select',
      '#title' => t('Move content in @layout to', array('@layout' => $old_layout_panels[$id])),
      '#options' => $options,
      '#default_value' => array_key_exists($id, $options) ? $id : $default,
    );
  }

  $form['back'] = array(
    '#type' => 'submit',
    '#value' => t('Back'),
    '#submit' => array('panels_choose_layout_back'),
  );

  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => $form_state['finish'],
    '#submit' => array('panels_change_layout_submit'),
    '#save-display' => TRUE,
  );
  return $form;
}

/**
 * Handle submission of the change layout form.
 *
 * This submit handler will move panes around and save the display.
 */
function panels_change_layout_submit($form, &$form_state) {
  $display = &$form_state['display'];

  if (!empty($form_state['values']['old'])) {
    foreach ($form_state['values']['old'] as $id => $new_id) {
      $content[$new_id] = array_merge((array) $content[$new_id], $display->panels[$id]);
      foreach ($content[$new_id] as $pid) {
        $display->content[$pid]->panel = $new_id;
      }
    }

    $display->panels = $content;
  }

  $display->layout = $form_state['layout'];
}

/**
 * Handle submission of the change layout form.
 *
 * This submit handler sets a flag on the form state, which is then used
 * by the calling wrapper to restart the process.
 */
function panels_choose_layout_back($form, &$form_state) {
  $form_state['back'] = TRUE;
}