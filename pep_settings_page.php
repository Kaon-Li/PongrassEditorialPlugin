<div class="wrap">
<h2><?php print PEP_PUGIN_NAME ." ". PEP_CURRENT_VERSION. "<sub>(Build ".PEP_CURRENT_BUILD.")</sub>"; ?></h2>

<form method="post" action="options.php">
    <?php
		settings_fields( 'pep-settings-group' );
	?>
	
    <table class="form-table">
        <tr valign="top">
        <th scope="row">IP White List</th>
        <td><input type="text" name="ip_white_list_1" value="<?php echo get_option('ip_white_list_1'); ?>" /></td>
        </tr>
         
        <tr valign="top">
        <th scope="row">IP White List</th>
        <td><input type="text" name="ip_white_list_2" value="<?php echo get_option('ip_white_list_2'); ?>" /></td>
        </tr>
        
        <tr valign="top">
        <th scope="row">IP White List</th>
        <td><input type="text" name="ip_white_list_3" value="<?php echo get_option('ip_white_list_3'); ?>" /></td>
        </tr>
        
        <tr valign="top">
        <th scope="row">IP White List</th>
        <td><input type="text" name="ip_white_list_4" value="<?php echo get_option('ip_white_list_4'); ?>" /></td>
        </tr>
        
        <tr valign="top">
        <th scope="row">IP White List</th>
        <td><input type="text" name="ip_white_list_5" value="<?php echo get_option('ip_white_list_5'); ?>" /></td>
        </tr>
        
        <tr valign="top">
        <th scope="row">Your current IP</th>
        <td><?php echo $_SERVER['REMOTE_ADDR'] ?></td>
        </tr>
    </table>
    
    <p class="submit">
    <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
    </p>
    
</form>

<p>
<?php

function get_image_sizes( $size = '' ) {
    $wp_additional_image_sizes = wp_get_additional_image_sizes();
 
    $sizes = array();
    $get_intermediate_image_sizes = get_intermediate_image_sizes();
 
    // Create the full array with sizes and crop info
    foreach( $get_intermediate_image_sizes as $_size ) {
        if ( in_array( $_size, array( 'thumbnail', 'medium', 'large' ) ) ) {
            $sizes[ $_size ]['width'] = get_option( $_size . '_size_w' );
            $sizes[ $_size ]['height'] = get_option( $_size . '_size_h' );
            $sizes[ $_size ]['crop'] = (bool) get_option( $_size . '_crop' );
        } elseif ( isset( $wp_additional_image_sizes[ $_size ] ) ) {
            $sizes[ $_size ] = array( 
                'width' => $wp_additional_image_sizes[ $_size ]['width'],
                'height' => $wp_additional_image_sizes[ $_size ]['height'],
                'crop' =>  $wp_additional_image_sizes[ $_size ]['crop']
            );
           
        }
    }
 
    // Get only 1 size if found
    if ( $size ) {
        if( isset( $sizes[ $size ] ) ) {
            return $sizes[ $size ];
        } else {
            return false;
        }
    }
    return $sizes;
}


print('<table>');

$sizes_array = get_image_sizes();
foreach ($sizes_array as $key => $size_entry)
{
    print('<tr><td>');
    print($key.'</td><td>'.$size_entry['width'].'</td><td>'.$size_entry['height']);
    print('</td></tr>');
}

print('</table>');

print('<p>'.PEP_LOGPATH.'</p>');

print('<p>'.ABSPATH.'</p>');


?>
</p>
</div>