<?php
/*
Plugin Name: CF7 Better DB Plugin
Plugin URI: https://github.com/amereservant/Contact-Form-7-Better-Database-Plugin
Version: 0.0.1
Author: David Miles
Description: Captures form submissions from Contact Form 7 plugin and stores the form data in the database.
License: GPLv3
*/

/**
 * CF7 Better Database Plugin
 *
 * This is a minimalistic approach to adding database support to the Contact Form 7 plugin.
 * Upon running into issues with the only other alternative and then seeing how many files
 * are involved in it, I decided to write a simplified plugin offering the needed functionality
 * without a bunch of code that just isn't necessary.
 *
 * !! IMPORTANT !! 
 * This plugin is still in the developmental stages and therefore not suitable for production
 * use yet!  There aren't any security issues with using it (except perhaps the media files
 * being accessible), but it's not practical to use in it's current state.
 *
 */

// Make sure this file is included for the _cf7bdb_add_attachment() function's dependencies
require_once ABSPATH .'wp-admin/includes/file.php';


/**
 * Catch Form Data
 *
 * This function uses the 'wpcf7_before_send_mail' filter hook in CF7 to grab the
 * data when a form is submitted and then process it.
 *
 * @param   object  $cf7    The WPCF7_ContactForm object for the submitted form.
 * @return  void
 * @since   0.0.1
 * @access  private
 * @todo    $cf7->skip_mail needs to be made an option in the admin panel.
 */
function _cf7bdb_catch_form( $cf7 )
{
    $values = array();
    $fields = array();

    //$cf7->skip_mail = true; // This skips sending the email and only stores the form in the database.
    
    // Store the form fields (This isn't important and can be removed ...)
    if( is_array($cf7->scanned_form_tags) )
    {
        foreach($cf7->scanned_form_tags as $field)
        {
            // Ignore the Submit button
            if( $field['type'] == 'submit' ) continue;
            
            $fields[] = array(
                'required' => strpos($field['type'], '*') !== false,
                'type'     => str_replace('*', '', $field['type']),
                'name'     => $field['name']
            );
        }
    } else {
        // Show NOTICE level error only if WP_DEBUG is set to true
        trigger_error('The `scanned_form_tags` property is not an array!');
    }
    
    // Get the subject, sender, and recipient values from the form's configuration
    $values = _cf7bdb_get_mail_parts( $cf7 );

    $values['files']     = $cf7->uploaded_files;
    $values['form_ID']   = $cf7->id;
    $values['form_data'] = $cf7->posted_data;

    $values['form_data']['form_fields']  = $fields;
    $values['form_data']['form_title']   = $cf7->title;

    // Try adding the form data to the database
    if( ($result = add_cf7db_entry($values)) === true )
        $msg = 'Your information has been successfully submitted!';
    else
        $msg = $result === false ? 'Your information couldn\'t be saved!':$result;

    // Hook the `wpcf7_display_message` hook and display our message.  (Currently overrides the plugin's messages)
    $omsg = 'return "'. $msg .'";';
    add_filter('wpcf7_display_message', create_function('$a', $omsg));
}
add_action('wpcf7_before_send_mail', '_cf7bdb_catch_form');

/**
 * Get Email Parts
 *
 * This uses the email template for the form to get the `subject`, `sender`, `recipient`,
 * and `body` data for the form being submitted.
 * The syntax is mainly from the WPCF7_ContactForm::compose_and_send_mail() method.
 *
 * @param   object  &$cf7   WPCF7_ContactForm object for the form being submitted.
 * @return  array           An array containing the keys 'subject', 'sender', 'recipient', and 'body'
 *                          data that will be sent in the email.
 * @since   0.0.1
 * @access  private
 */
function _cf7bdb_get_mail_parts( &$cf7 )
{
    $mail_template = $cf7->mail;
    $regex      = '/\[\s*([a-zA-Z_][0-9a-zA-Z:._-]*)\s*\]/';
	$callback   = array( &$cf7, 'mail_callback' );
	
    $subject    = preg_replace_callback( $regex, $callback, $mail_template['subject'] );
	$sender     = preg_replace_callback( $regex, $callback, $mail_template['sender'] );
	$recipient  = preg_replace_callback( $regex, $callback, $mail_template['recipient'] );

    if( (bool)$mail_template['use_html'] ){
        $body = preg_replace_callback( $regex, array(&$cf7, 'mail_callback_html'), $mail_template['body'] );
        $body = wpautop( $body );
    } else {
        $body = preg_replace_callback( $regex, $callback, $mail_template['body'] );
    }

	return compact('subject', 'sender', 'recipient', 'body');
}

/**
 * Add Custom Post Type
 *
 * Adds a custom post type for CF7BDB entries so the plugin uses already-existing
 * database tables and functions for storing/retrieving the data instead of creating
 * additional ones.
 *
 * This also adds a new menu section in the administrator menu to view/edit the entries,
 * all using WordPress's built-in functionality.
 *
 * Additional filtering needs to be added to prevent adding new posts via the admin panel,
 * add columns to the posts view, etc. which all can be implimented rather easily.
 *
 * @param   void
 * @return  void
 * @since   0.0.1
 * @access  private
 */
function _add_cf7bdb_post_type()
{
    register_post_type( 'cf7bdb_entries', array(
        'label'     => 'CF7 Better Database Plugin Entries',
        'labels'    => array(
                            'singular_name' => 'CF7BDB Entry',
                            'all_items'     => 'View Entries',
                            'edit_item'     => 'Edit Entry',
                            'new_item'      => 'New Entry',
                            'view_item'     => 'View Entry',
                            'search_items'  => 'Search Form Submissions',
                            'not_found'     => 'No matching entries were found',
                            'menu_name'     => 'CF7 Entries',
                       ),
        'description'       => 'Submitted form entries for Contact Form 7.',
        'show_ui'           => true,
        'show_in_menu'      => true,
        'menu_position'     => 5,
        'hierarchical'      => true, // Allows specifying a parent, which will be the Form ID
        'supports'          => array('title', 'editor', 'author', 'custom-fields', 'revisions'),
    ) );
}
add_action('init', '_add_cf7bdb_post_type');

/**
 * Add Form Data To Database
 *
 * This stores the submitted form data as a new post and adds the post meta for it.
 * It should ONLY be called by {@link _cf7bdb_catch_form()}.
 *
 * It will also call {@link _cf7bdb_add_attachment()} to add any files that were submitted
 * with the form to WordPress's Media Library.
 *
 * @param   array   $values     The processed form data to create the new entry with.  See
 *                              the {@link _cf7bdb_catch_form()} for details.
 * @return  bool|string         (bool)true if successful, error message if failed.
 * @since   0.0.1
 * @access  private
 */
function _add_cf7db_entry( $values )
{
    // Get the user's details if they're logged in...
    $user = wp_get_current_user();

    // Form the post data to create new post with
    $post['comment_status'] = 'closed'; // Disable comments
    $post['ping_status']    = 'closed'; // Disable pingbacks
    $post['post_author']    = $user->ID;
    $post['post_content']   = $values['body']; // Store the email body as the post content
    $post['post_parent']    = $values['form_ID'];
    $post['post_status']    = 'pending'; // Set the default post status to 'pending'
    $post['post_title']     = $values['subject'];
    $post['post_type']      = 'cf7bdb_entries';

    // Insert the post data.  Setting the second param will return an WP_Error object if it fails.
    $postID = wp_insert_post( $post, true );

    if( is_a($postID, 'WP_Error') ) {
        return $postID->get_error_message();
    }
    // This shouldn't ever test true ...
    elseif( !is_numeric($postID) ) {
        trigger_error('Inserting posted failed!', E_USER_ERROR);
    }
    
    // Loop over the posted values and add them as post meta.
    foreach($values['form_data'] as $key => $val)
    {
        add_post_meta($postID, $key, $val, true) or update_post_meta($postID, $key, $val);
    }
    
    // Itterate over any submitted files and attach them to the post
    foreach($values['files'] as $k => $file)
    {
        $upload = cf7bdb_add_attachment( $file, $postID );
    }
    
    return true;
}

/**
 * Add File Attachments
 *
 * This handles adding any uploaded files to the WordPress Media Library and attaching
 * them to the posted form data.
 * It requires the 'wp-admin/includes/file.php' file to be loaded for some of the functions used.
 *
 * @param   string  $file   The full path for the file that was uploaded.
 * @param   integer $pid    The postID the attachment is for.
 * @return  bool|string     (bool)true on success or a string describing the error on failure.
 * @since   0.0.1
 * @access  private
 */
function _cf7bdb_add_attachment( $file, $pid )
{
    $filename   = basename($file);
    $testtype   = wp_check_filetype_and_ext($file, $filename, null);

    // Check if a proper filename was given for in incorrect filename and use it instead
    if( $testtype['proper_filename'] )
        $filename = $testtype['proper_filename'];

    if( (!$testtype['type'] || !$testtype['ext']) && !current_user_can( 'unfiltered_upload' ) )
        return __('Sorry, this file type is not permitted for security reasons.');

    if( !$testtype['ext'] )
        $testtype['ext'] = ltrim(strrchr($filename, '.'), '.');
    
    // Check if the uploads directory exists/create it.  If it fails, the parent directory probably isn't writable.
    if( !($uploads = wp_upload_dir(null)) )
        return $uploads['error'];

    // Correct the directory separators, mainly on Windows servers...
    //$uploads['path'] = realpath($uploads['path']);
    
    $filename = wp_unique_filename( $uploads['path'], $filename );
    
    $new_file = $uploads['path'] .'/'. $filename;
    
    // Copy the uploaded file to the correct uploads folder.
    if( false === @copy($file, $new_file) )
        return sprintf(__('The uploaded file could not be moved to %s.'), $uploads['path']);

    // Set correct file permissions
    $stat   = stat(dirname($new_file));
    $perms  = $stat['mode'] & 0000666;
    @chmod( $new_file, $perms );
    
    $attachment = array(
        'post_mime_type'    => $testtype['type'],
        'post_title'        => preg_replace('/\.[^.]+$/', '', $filename),
        'post_content'      => '',
        'post_status'       => 'inherit',
    );
    // Add the attachment
    $att_id = wp_insert_attachment( $attachment, $new_file, $pid );
    
    // Test for an image and only perform the rest if the upload is an image...
    if( @getimagesize($new_file) !== false )
    {
        require_once ABSPATH .'wp-admin/includes/image.php';
        
        $att_data = wp_generate_attachment_metadata( $att_id, $new_file );
        wp_update_attachment_metadata( $att_id, $att_data );
    }
    return true;
}
