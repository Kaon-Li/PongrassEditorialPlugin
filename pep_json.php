<?php
/*
 COPYRIGHT

Adopted from the code by Sergio Vaccaro in JSON-RPC PHP


You can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

JSON-RPC PHP is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with JSON-RPC PHP; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
 * This class build a json-RPC Server 1.0
 * http://json-rpc.org/wiki/specification
 *a
 * @author Ronin <ronin@pongrass.com.au>
 * 
 */


require_once('pep_config.php');
require_once('pep_logfilehandling.php');
//require_once(ABSPATH . WPINC .'./wp-load.php');
//require_once (ABSPATH .'/wp-load.php');




class PEPjsonServer {
	/**
	 * This class implemented the main jsonrpc entry point 
	 * 
	 * 
	 *
	 * 
	 * @return boolean
	 */
	
	public static $instance;
	
	public $function_array = array();
	
	
	
	
	
	private function __construct() { 
		
	}
	
	public static function getInstance()
	{
		if(!static::$instance) {
			static::$instance = new self();
		}
		return static::$instance;
	}
	
	// register function handler
	
	public function register_handler($name, $callback_function)
	{
		$function_array[$name] = $callback_function;
	}
	
	public function has_handler($name)
	{
		if (isset($function_array[$name]))
		{
			return true;
		}
		
		return false;
	}
	
	public function pep_invalid_content()
	{
		pep_writelog('Not a valid json request');
		header('content-type: application/json');
		echo json_encode('Not a valid JSON request');	
	}
	
	public function pep_submit_post($request)
	{
		$id = $request['id'];
		$content = $request['params'];
		pep_writelog('Post content is '.json_encode($content));
		
		// see if the content contains the post_id
		$update_post_id = $content["@update_post_id"];
		if ($update_post_id)
		{
		    pep_writelog('updating post '.$update_post_id);
		    //$myvals = get_post_meta($update_post_id);
            //foreach($myvals as $key=>$val)  {
            //    delete_post_meta($update_post_id, $key);
            //}
            delete_post_meta($update_post_id, "attachment_data");
            delete_post_meta($update_post_id, "image_meta");
            $content["@update_post_id"] = null;
            $content["ID"] = (int)$update_post_id;
            
            
	        wp_update_post($content, false, false);
	      
	        $post_id = (int)$update_post_id;
		}
		else {
		    $post_id = wp_insert_post($content);
		};
	
		pep_writelog('Post ID is '.$post_id);
		
		$result_array = array(
				'post_id' => $post_id,
				'permalink' => get_permalink($post_id),
				'comment' => 'post ok'
		);
		$response = array (
				'id' => $id,
				'result' => $result_array,
				'error' => NULL
		);
		
		return $response;
	}
	
	public function pep_update_post_status($request)
	{
		$id = $request['id'];
		$content = $request['params'];
		
		pep_writelog('Post content is '.json_encode($content));
		$post_id = wp_update_post($content, false, false);
		
		$result_array = array(
				'post_id' => $post_id,
				'comment' => 'update ok'
		);
		
		$response = array (
				'id' => $id,
				'result' => $result_array,
				'error' => NULL
		);
		
		return $response;
		
	}
	
	public function startsWith($haystack, $needle)
    {
		return !strncmp($haystack, $needle, strlen($needle));
	}
	
	public function get_taxonomies_for_post($post_id) 
	{
    
        
    // Get post type taxonomies.
        $taxonomies = get_object_taxonomies(get_post_type($post_id), 'objects' );
 
        $out = array();
 
        foreach ( $taxonomies as $taxonomy_slug => $taxonomy )
        {
 
            // Get the terms related to post.
            $terms = get_the_terms( $post_id, $taxonomy_slug );
     
            if ( ! empty( $terms ) ) {
                $out[$taxonomy->label] = $terms;
               
            }
        }
        return $out; 
    }
	
	public function pep_set_meta_data($request)
	{
		$id = $request['id'];
		
		$param_meta_data = $request['params'];
		
		pep_writelog(json_encode($param_meta_data), 0);
		
		
		
		$post_id = $param_meta_data['post_id'];
		$has_term_id = $param_meta_data['has_term_id'];
		$meta_info = $param_meta_data['data'];
		$cate_added = false;
		
		
		// it is an object of array..
		foreach ((array)$meta_info as $key => $value_array)
		{
			// value is also an array..
			pep_writelog('Setting meta for key '.$key, 0);
			delete_post_meta($post_id, $key);
			foreach($value_array as $value)
			{
			    
				if ($key == 'category')
				{
				    if (isset($has_term_id))
				    {
    					// assigning category..
    					pep_writelog('Assigning category '.$value, 0);
    					$data_category = get_term_by('name',$value,'category');
    					if ($data_category != null)
    					{
    		
    						pep_writelog('Category number for '.$value.' is '.$data_category->term_id);
    						wp_set_object_terms($post_id, (int)$data_category->term_id, 'category', $cate_added);
    						$cate_added = true;
    					}
    					else {
    						pep_writelog('Category '.$value.' not found', 0);
    					}
				    }
				    else {
				        pep_writelog('Bypassing category since termid is set', 0);
				    }
				}
				elseif ($key == 'category_term_id')
				{
					// assigning category..
					pep_writelog('Assigning category by id '.$value, 0);
					
					wp_set_object_terms($post_id, (int)$value, 'category', $cate_added);
					$cate_added = true;
				
				}
				elseif ($key == 'ad_cat')
				{
					pep_writelog('Assigning ad categories by id'.$value, 0);
					wp_set_object_terms($post_id,(int)$value, 'ad_cat', $cate_added);
					$cate_added = true;
					
				}
				elseif ($this->startsWith($key, 'press_class'))
				{
					// assigning category..
					pep_writelog('Assigning press class '.$value.' for key'.$key, 0);
					$data_class = get_term_by('name',$value,$key);
					if ($data_class != null)
					{
						pep_writelog('Press Class term number for '.$key.' and value '.$value.' is '.$data_class->term_id);
						wp_set_object_terms($post_id, (int)$data_class->term_id, $key, $cate_added);
						$cate_added = true;
					}
					else {
						if (($value != null) && ($value != ''))
						{
							error_log($key.' does not exist, will create', 0);
						
							$new_term = wp_insert_term($value, $key);
							if (!is_wp_error( $new_term ))
							{
								wp_set_object_terms($post_id, (int)$new_term['term_id'], $key, $cate_added);
							}
							else {
								pep_writelog('Creation of taxonomy '.$key.' for value '.$value.' failed', 0);
							}
						
						}
					}
				}
				else {
				    
				    if (taxonomy_exists($key))
				    {
				       // only set it if the term already exists.
				       $term_id = get_term_by('name',$value,$key);
				       
				       if (isset($term_id))
				       {
				          wp_set_post_terms($post_id, array($value), $key);
				          pep_writelog('Inserting taxonomy value key='.$key.', value = '.$value, 0);   
				       }
				       else {
				           pep_writelog('Skipping taxonomy value key='.$key.', value = '.$value.' because it is not found', 0);   
				       }
				    }
				    else {
					    pep_writelog('Inserting Meta value key='.$key.', value = '.$value, 0);
					    add_post_meta($post_id, strtolower($key), $value, false);
				    }
				}
			}
				
		}
		$result_array = array(
				'post_id' => $post_id,
				'comment' => 'post ok',
				'taxonomy' => $this->get_taxonomies_for_post($post_id),
				'meta' => get_post_meta($post_id)
				
		);
		$response = array (
				'id' => $id,
				'result' => $result_array,
				'error' => NULL
		);
		error_log(json_encode($response), 0);
		
		return $response;
	
	}
	
	public function pep_link_uploaded_file($request)
	{
	    
	    
		// first, see if the file exists..
		$id = $request['id'];
		
		$fileinfo = $request['params'];
		$filename = $fileinfo['filename'];
        $caption = $fileinfo['caption'];
        if (!isset($caption)) $caption = '';
		$use_caption_shortcode = $fileinfo['use_caption_shortcode'];
		$upload_as_block = $fileinfo['upload_as_block'];
        
        $image_size = $fileinfo['image_size'];
        $image_width = 640;
        $image_height = 400;
        if (isset($image_size))
        {
            $image_width = $fileinfo['image_width'];
            $image_height = $fileinfo['image_height'];
        }
       
        
		$pid = $fileinfo['post_id'];
		$upload_dir = WP_CONTENT_DIR.'/uploads/';
                if (!is_writable($upload_dir))
                {
                     error_log($upload_dir).' is not writable';
                }
		$dest_path = trailingslashit($upload_dir).$filename;
                require_once( ABSPATH . 'wp-admin/includes/image.php' );
 	        require_once( ABSPATH . 'wp-admin/includes/file.php' );
	        require_once( ABSPATH . 'wp-admin/includes/media.php' );


                if (!is_writable($dest_path))
                {
                     pep_writelog($dest_path).' is not writable';
                }

		
		
		$attach_id = 0;
		
		
			if ($_FILES)
		        {
		            
		            
                        
                         foreach ($_FILES as $attrName => $valuesArray) {
                                
                               
                                pep_writelog('processing files ID '.$attrName." for post ".$pid);
                                
                                if (isset($image_size))
                                {
                                    add_image_size($image_size,$image_width,$image_height,true);
                                }
                                
                                  $post_data = array(
									'post_excerpt' => $caption,  // caption
									'post_content' => $caption  // description
								);
                                
                                $attach_id = media_handle_upload($attrName, $pid, $post_data);
                               
                               
                               if (is_wp_error($attach_id))
                               {
                                   $error_string = $attach_id->get_error_message();
                                   pep_writelog('Error with media upload '.$error_string);
                                   $response = array(
                						'id' => $id,
                						'error' => array(
                							'code' => -32002,
                							'message' => 'Cannot copy uploaded file '.$error_string
                						)
                					);
                					
                					return $response;
                               }
                            
                            
                                pep_writelog('attach id is '.$attach_id);
                               
                				if ($attach_id )
                				{    
                                                      $my_post = array(
                										'ID'           => $attach_id,
                										'post_excerpt' => $caption,  // caption
                										'post_content' => $caption,  // description
                										'parent_post' => $pid // parent post, in case it needs it
                						  );
                				     //  wp_update_post( $my_pos);
                				      // wp_update_image_subsizes($attach_id);
                				}
                				else {
                                                       pep_writelog('File cannot be moved due to error code '.$valuesArray['error']);
                					pep_writelog('File cannot be found at '.$valuesArray['tmp_name']);
                					pep_writelog('Cannot copy to '.$dest_path);
                					$response = array(
                						'id' => $id,
                						'error' => array(
                							'code' => -32002,
                							'message' => 'Cannot copy uploaded file '.$_FILES['userfile']['tmp_name'].' to '.$dest_path
                						)
                					);
                				
                					return $response;
                				}
                         }
                        
		}
		else {
			if (copy(trailingslashit(FTP_ROOT).$filename,$dest_path))
			{
				// unlink(trailingslashit(FTP_ROOT).$fileinfo['filename']);

			}
			else {
				pep_writelog('File cannot be found at '.trailingslashit(FTP_ROOT).$filename);
				pep_writelog('Cannot copy to '.$dest_path);
				$response = array(
					'id' => $id,
					'error' => array(
						'code' => -32001,
						'message' => 'Cannot copy from '.trailingslashit(FTP_ROOT).$filename.' to '.$dest_path
					)
				);
				return $response;
			}
		}
		
             
		
		
		if ($fileinfo['featured'])
		{
		  // must add a thumbnail id to it..
		  // add_post_meta($pid, '_thumbnail_id', $attach_id, true);
		  $res = set_post_thumbnail($pid, $attach_id);
		   pep_writelog('set_post_thumbnail result is'.$res);
		   if ($res == false)
		   {
			    pep_writelog('Using set post meta instead');
			    add_post_meta($pid, '_thumbnail_id', $attach_id, true);
		   }
		}
		else
		{
		    pep_writelog('trying to link the image to attachment');
			 // add the attachment url to the end of the list..
			 $content_post = get_post($pid);
             $content = $content_post->post_content;
			 // $content = apply_filters('the_content', $content);
			 
			 $picture_marker = '<!-- PictureMarker['.$filename.']-->';

			 $image_prefix = chr(0x0A).'<!-- wp:image {"className":"aligncenter"} -->'.chr(0x0A).'<figure class=\"wp-block-image aligncenter\">';
			 $image_suffix = '</figure>'.chr(0x0A).'<!-- /wp:image -->'.chr(0x0A);
			 
			 if (strpos($content, $picture_marker))
			 {
			     pep_writelog('picture marker found');
			     $text_to_replace = '<img class="aligncenter wp-image-30038 size-large" src="'.wp_get_attachment_url($attach_id).'" alt="" />';
			     if ($caption != '')
    			 {
    			   $text_to_replace = $text_to_replace.'<figcaption class="tdb-caption-text">'.$caption.'</figcaption>';
    			 }
    			
    			 $content = str_replace( $picture_marker, $text_to_replace, $content);
    			
			 }
			 else {
			    if ('1' == $use_caption_shortcode)
				{
				    pep_writelog('using shortcode for images');
				    $i_size = '';
				    if (isset($image_size))
				    {
						if ($image_size <> 'custom')
						{
							$i_size = ' size-'.$image_size;
						}
				    }
				    if ($caption <> '')
				    {
					
    					$content = $content.'[caption id="'.$attach_id.'" align="aligncenter" width="'.$image_width.'" caption="'.$caption.'"]';
    					$content = $content.'<img class="wp-image-'.attachment_url_to_postid(wp_get_attachment_url($attach_id)).$i_size.'" src="'.wp_get_attachment_url($attach_id).'" alt="" width="'.$image_width.'" height="'.$image_height.'">';
    					 
    					 $content = $content."[/caption]";
				    }
				    else {
						
						
				        $content = $content.'<img class="aligncenter wp-image-'.attachment_url_to_postid(wp_get_attachment_url($attach_id)).$i_size.'" src="'.wp_get_attachment_url($attach_id).'" alt="" width="'.$image_width.'" height="'.$image_height.'">';
    			
				    }
					 
					  
					
				}
				else if ('1' == $upload_as_block)
				{
					$content = $content.$image_prefix.'<img src="'.wp_get_attachment_url($attach_id).'" alt="" />';
					 if ($caption != '')
					 {
						$content = $content.'<figcaption>'.$caption.'</figcaption>';
					 }
					 $content = $content.$image_suffix;
				}
				else {
					$content = $content.'<img class="aligncenter wp-image-30038 size-large" src="'.wp_get_attachment_url($attach_id).'" alt="" />';
					 if ($caption != '')
					 {
						$content = $content.'<figcaption class="tdb-caption-text">'.$caption.'</figcaption>';
					 }

				}
			 }
			 $content_post->post_content = $content;
			 wp_update_post($content_post, false, false);
		}

        $attach_data = wp_get_attachment_metadata($attach_id);		

		$res_object['data'] = $attach_data;
		
		$response = array(
				'id' => $id,
				'result' => $res_object
		);
		pep_writelog(json_encode($response), 0);
		
		return $response;
	}
	
	public function pep_get_authors($request)
	{
	    pep_writelog('obtaining authors');
	    $id = $request['id'];
	    $params = $request['params'];
	    $logged_on = 0;
	    if ($params)
	    {
	        // get the login
	        $user = get_user_by( 'login', $params['login'] );
            if ($user)
            {
                wp_set_current_user($user->ID);
                $logged_on = 1;
            }
	    }
	    $args = array(
           'role__in'     => array('editor', 'author'),
            'orderby'      => 'display_name',
            'fields' => array('display_name','user_login','ID','user_email')
        );
        
       
        
       
	    $authors = get_users($args);
	    if (count($authors) == 0)
	    {
	       // global $wpdb;
	       // $tableprefix = $wpdb->base_prefix;
	        //     $sqlquery = "SELECT user_login, user_email,display_name, ID, meta_value from ".$tableprefix."users left join ".$tableprefix."usermeta on ".$tableprefix."users.ID = ".$tableprefix."usermeta.user_id WHERE ";
	        // $sqlquery .=$tableprefix."usermeta.meta_key = '".$tableprefix."capabilities' and (meta_value like '%administrator%' or meta_value like '%author%' or meta_value like '%editor%')";
	  
	        //$authors = $wpdb->get_results($sqlquery);
	    }
	    pep_writelog('authors OK');
		$response = array (
				'id' => $id,
				'result' => $authors,
				'error' => null
		);
		pep_writelog('response created '.json_encode($response));
		if ($logged_on)
		{
		    wp_logout();
		}
	    return $response;
	}
	
	public function pep_get_post_by_id($request)
	{
		pep_writelog('obtaining post by id');
	    $id = $request['id'];
	    $id_arrays = array();
	    $id_arrays[0] = (int)$id;
	    $post = get_post($id);
		$articles = array($post);
	    pep_writelog('obtain '.count($articles).' posts');
	    
	    $thumbnail_id = get_post_meta($id, '_thumbnail_id', true);
	    $attach_data = array();
	    if ($thumbnail_id)
	    {
	         $attach_data = wp_get_attachment_metadata($thumbnail_id);		
	    }
	    
		$response = array (
				'id' => $id,
				'result' => array ('articles' => $articles,
				    'meta'=> get_post_meta($id),
				    'taxonomy' => $this->get_taxonomies_for_post($id),
				    'attachment_data' => $attach_data),
				'error' => null
		);
		pep_writelog('response created '.json_encode($response));
	    return $response;
		
	}
	
		public function pep_get_post_by_form_id($request)
	{
		pep_writelog('obtaining post by id');
	    $id = $request['id'];
		$meta_value = $request['params']['form_number'];
		$articles = get_posts(
             array(
			  'meta_key' => 'form_number',
			  'meta_value' => $meta_value,
			  'meta_compare' => '=', 
              'numberposts' => 10,
              'post_type' => get_post_types('', 'names'),
			  'lang' => array('en','zh')
             )
            );
	    pep_writelog('obtain '.count($articles).' posts');
	    $metaarray = array();
	    foreach ($articles as $mypost)
	    {
	        $metaarray[] = get_post_meta($mypost->ID);
	    }
		$response = array (
				'id' => $id,
				'result' => array ('articles' => $articles,'meta'=> $metaarray),
				'error' => null
		);
		pep_writelog('response created '.json_encode($response));
	    return $response;
		
	}
	
	public function pep_cancel_post_by_form_id($request)
	{
		pep_writelog('cancel post by form id');
	    $id = $request['id'];
		$meta_value = $request['params']['form_number'];
		$articles = get_posts(
             array(
			  'meta_key' => 'form_number',
			  'meta_value' => $meta_value,
              'numberposts' => 10,
              'post_type' => get_post_types('', 'names'),
              'lang' => array('en','zh')
             ));
            
		pep_writelog(json_encode($articles));
	    pep_writelog('obtain '.count($articles).' posts');
		
		foreach ($articles as $mypost)
		{
           pep_writelog('Cancelling post '.$mypost->ID);
			wp_delete_post($mypost->ID, true);
			
		};
		
		$response = array (
				'id' => $id,
				'result' => array('result' => 'Removed '.count($articles).' posts'),
				'error' => null
		);
		pep_writelog('response created '.json_encode($response));
	    return $response;
		
	}
	
	public function pep_get_published_post($request)
	{
	    pep_writelog('obtaining all published posts');
	    $id = $request['id'];
	   $articles = get_posts(
             array(
              'numberposts' => -1,
              'post_status' => array('publish'),
              'post_type' => get_post_types('', 'names'),
              'lang' => array('en','zh')
             )
            );
	    pep_writelog('obtain '.count($articles).' posts');
	    $result = array();
	    foreach ($articles as $mypost) {
	        $media = get_attached_media('',$mypost);
	        $result[] = array($mypost->post_title, $mypost->post_content, $mypost->ID, 
	        get_the_post_thumbnail($mypost), $media);
	    };
	    
	    
		$response = array (
				'id' => $id,
				'result' => $result,
				'error' => null
		);
		pep_writelog('response created '.json_encode($response));
	    return $response;
	}
	
	public function pep_get_categories($request)
	{
		pep_writelog('obtaining categories');
		$id = $request['id'];
		
		//$categories = get_terms('category', array(
		//		'orderby'    => 'count',
		//		'hide_empty' => 0
		//));
		$categories = get_terms(array(
		    'taxonomy' => 'category',
		    'hide_empty' => false));
		pep_writelog('categories OK');
		$response = array (
				'id' => $id,
				'result' => $categories,
				'error' => null
		);
		pep_writelog('response created '.json_encode($response));
		return $response;
	}
	
	public function pep_get_version($request)
	{
		pep_writelog('obtaining version number');
		
		$VersionNumber = '2.59';
        $VersionDate = '2024-09-03';
    
    
		$id = $request['id'];
		
		
		$response = array (
				'id' => $id,
				'result' => array ('version' => $VersionNumber, 
				                    'version_date' => $VersionDate),
				'error' => null
		);
		pep_writelog('response created '.json_encode($response));
		return $response;
	}
	
	public function pep_publish_acd_classified($request)
	{
		// combines the posting of data, updating of meta, and deleting the old post all in one..
	    $id = $request['id'];
		$content = $request['params'];
		pep_writelog('Post content is '.json_encode($content));
		$form_number = $content['form_number'];
		
		$articles = get_posts(
            array(
			  'meta_key' => 'form_number',
			  'meta_value' => $form_number,
              'numberposts' => 1,
              'post_type' => get_post_types('', 'names'),
              'lang' => array('en','zh')
             )
            );
			
		// cancel them all
		foreach ($articles as $mypost)
		{
			wp_delete_post($mypost['ID'], true);
			pep_writelog('Deleted post '.$post_id);
		}
		
		$content['form_number'] = null;
		
		
		$post_id = wp_insert_post($content);
		pep_writelog('Post ID is '.$post_id);
		
		$result_array = array(
				'post_id' => $post_id,
				'permalink' => get_permalink($post_id),
				'comment' => 'post ok'
		);
		$response = array (
				'id' => $id,
				'result' => $result_array,
				'error' => NULL
		);
		
		return $response;
		
		
		
	}
	
	public function pep_publish_advert($request)
	{
		$id = $request['id'];
		$post_id = wp_insert_post($request['params']);
		
		// force the category to breaking..
		//wp_set_object_terms($post_id, 3, 'category', false);
		
		// set the location to unallocated
		wp_set_object_terms($post_id, 'unallocated', 'post_tag', false);
		
		
		$result_array = array(
				'post_id' => $post_id,
				'comment' => 'post ok'
		);
		$response = array (
				'id' => $id,
				'result' => $result_array,
				'error' => NULL
		);
		return $response;
	}
	
	public function pep_cancel_post($request)
	{
		$id = $request['id'];
		$post_id = $request['params']['post_id'];
		$success = wp_delete_post($post_id, true);
		return $success;
	}
	
	public function PerformAction() {

		// checks if a JSON-RCP request has been received
		
		// the valid methods are
		// pep_submit_post
		//
		// pep_update_post
		//
		// pep_remove_post
		//
		//
		// 
		// 
		ignore_user_abort(true);
        set_time_limit(0);
		http_response_code (200);
        header('content-type: application/json');
        // ob_flush();
		
		// check the whitelist..
		$whitelist_1 = file_get_contents(dirname(__FILE__).'/whitelist_1.txt');
		if ($whitelist_1)
		{
		    pep_writelog('Whitelist1='.$whitelist_1, 'Perform Action', '490');
		}
		
		$whitelist_2 = file_get_contents(dirname(__FILE__).'/whitelist_2.txt');
		if ($whitelist_2)
		{
		    pep_writelog('Whitelist2='.$whitelist_2, 'Perform Action', '490');
		}
		
		$whitelist_3 = file_get_contents(dirname(__FILE__).'/whitelist_3.txt');
		if ($whitelist_3)
		{
		    pep_writelog('Whitelist3='.$whitelist_3, 'Perform Action', '490');
		}
		
		$whitelist_4 = file_get_contents(dirname(__FILE__).'/whitelist_4.txt');
		if ($whitelist_4)
		{
		    pep_writelog('Whitelist4='.$whitelist_4, 'Perform Action', '490');
		}
		
		$whitelist_5 = file_get_contents(dirname(__FILE__).'/whitelist_5.txt');
		if ($whitelist_5)
		{
		    pep_writelog('Whitelist5='.$whitelist_5, 'Perform Action', '490');
		}
		
		
		
		if (($whitelist_1 != '') ||
		    ($whitelist_2 != '') ||
		    ($whitelist_3 != '') ||
		    ($whitelist_4 != '') ||
		    ($whitelist_5 != ''))
		    {
		        if (($whitelist_1 != $_SERVER['REMOTE_ADDR']) && 
		            ($whitelist_2 != $_SERVER['REMOTE_ADDR']) && 
		            ($whitelist_3 != $_SERVER['REMOTE_ADDR']) && 
		            ($whitelist_4 != $_SERVER['REMOTE_ADDR']) && 
		            ($whitelist_5 != $_SERVER['REMOTE_ADDR']))
		           {
		               pep_writelog('JSON request received from non white listed address', 'Perform Action', '513');
                       
		                $response = array (
					        'id' => 0,
					        'result' => NULL,
					        'error' => 'Request not from white listed ip'
			            );
			            
			            header('content-type: application/json');
			            echo json_encode($response);
			            http_response_code (403);
			            return true;
			            
		           }
		    }
		
		
		
		$jsontext = '';
		if ($_SERVER['CONTENT_TYPE'] == 'application/json')
		{
			pep_writelog('JSON request received', 'Perform Action', '117');
                        pep_writelog('JSON request received');
			  $jsontext = file_get_contents('php://input');
		}
		else {
			  pep_writelog('File Upload Request', 'Perform File Upload', '118');
                          pep_writelog('Content Type received '.$_SERVER['CONTENT_TYPE']);
			  // multipart form data..
			  $jsontext = stripslashes($_REQUEST['request']);
		}

		// reads the input data
                pep_writelog($jsontext);
		$request = json_decode($jsontext,true);
                $switched = false;
		// executes the task on local object
		try {
			$id = $request['id'];
			// only a few method is supported..
			$method = $request['method'];
                        $blog_id = $request['params']['blog'];
			if ($blog_id)
			{
				$switched = true;
				switch_to_blog($blog_id);
			};


			pep_writelog('Json method name is '.$method );
			if ($method == 'pep_submit_post')
			{
				/*
				$post_id = wp_insert_post($request['params']);
				
				// force the category to breaking..
				//wp_set_object_terms($post_id, 3, 'category', false);
				
				$result_array = array(
						'post_id' => $post_id,
						'comment' => 'post ok'
						);
				$response = array (
						'id' => $id,
						'result' => $result_array,
						'error' => NULL
				);
				*/
				$response = $this->pep_submit_post($request);

			}
			else if ($method == 'pep_cancel_post')
			{
				$post_id = $request['params']['post_id'];
				$success = $this->pep_cancel_post($request);
				if ($success)
				{
					$response = array (
							'id' => $id,
							'result' => array('status'=>'success',
											  'post_id'=>$post_id
									),
							'error' => null
					);
				}
				else {
					$response = array (
							'id' => $id,
							'result' => NULL,
							'error' => 'cannot cancel post'
					);
				}
			}
			else if ($method == 'pep_update_post_status')
			{
				$response = $this->pep_update_post_status($request);
			}
			else if ($method == 'pep_set_meta_data')
			{
				$response = $this->pep_set_meta_data($request);
				

			}
			else if ($method == 'pep_link_uploaded_file')
			{
				$response = $this->pep_link_uploaded_file($request);		
			} 
			else if ($method == 'pep_get_upload_dir')
			{
				$result = wp_upload_dir();
				$response = array (
						'id' => $id,
						'result' => $result,
						'error' => null
						);
			}
			else if ($method == 'pep_get_categories')
			{
				$response = $this->pep_get_categories($request);
				
				
			}
			else  if ($method == 'pep_get_authors')
			{
			    $response = $this->pep_get_authors($request);
			}
			else  if ($method == 'pep_get_published_post')
			{
			    $response = $this->pep_get_published_post($request);
			}
			else  if ($method == 'pep_get_post_by_id')
			{
			    $response = $this->pep_get_post_by_id($request);
			}
			else  if ($method == 'pep_get_post_by_form_id')
			{
			    $response = $this->pep_get_post_by_form_id($request);
			}
			else  if ($method == 'pep_cancel_post_by_form_id')
			{
			    $response = $this->pep_cancel_post_by_form_id($request);
			}
			else  if ($method == 'pep_publish_acd_classified')
			{
				$response = $this->pep_publish_acd_classified($request);
			}
			else  if ($method == 'pep_get_version')
			{
			    $response = $this->pep_get_version($request);
			}
			else if ($method == 'pep_get_ad_dimensions')
			{
				global $_wp_additional_image_sizes;
				//$adsize_name_array = array('Top Banner','Size Banner Small');
				// $image_sizes = get_intermediate_image_sizes();
				//$ress = array();
				//$k = 0;
				
				// get the sidebar array
				$ress = wp_get_sidebars_widgets();
				
				
				/*
				$adsize_array = array(
						array (
								'name' => 'Top Banner',
								'id' => 'press-zone-h3',
								'size' => 'press-ad-bill'
						),
						array (
								'name' => 'Side Banner Small',
								'id' => 'press-zone-h5',
								'size' => 'press-ad-medr'
						),
						array (
								'name' => 'Side Banner Medium',
								'id' => 'press-zone-h5',
								'size' => 'press-ad-film'
						),
						array (
								'name' => 'Side Banner Large',
								'id' => 'press-zone-h5',
								'size' => 'press-ad-port'
						),
						array (
								'name' => 'Lower Side Banner',
								'id' => 'press-zone-h7',
								'size' => 'press-ad-medr'
						),
						array (
								'name' => 'Bottom Banner',
								'id' => 'press-zone-h11',
								'size' => 'press-ad-lead'
						),
							
						// General
							
						array (
								'name' => 'General Side Banner Medium',
								'id' => 'press-zone-g3',
								'size' => 'press-ad-film'
			
						),
						array (
								'name' => 'General Side Banner Large',
								'id' => 'press-zone-g3',
								'size' => 'press-ad-port'
		
						)
				);
				*/
				
				//foreach($image_sizes as $x )
				//{
					
				//	$width = $_wp_additional_image_sizes[$x]['width'];
				//	$height = $_wp_additional_image_sizes[$x]['height'];
				//	if (($width != null) && ($height != null))
				//	{
				//		$ress[$k] = $x." ".$width."X".$height;
				//		$k++;
				//	}
					
				//}
				//*/
				/*
				foreach($adsize_array as $x)
				{
					error_log('Adsize Array entry'.$k.'='.$x, 0);
					$ress[$k] = $x['name'];
					$k++;	
				}
				*/
				
				//$image_sizes = $wp_additional_image_sizes;
				$response = array (
						'id' => $id,
						'result' => $ress,
						'error' => null
				);
			
			}
			else if ($method == 'pep_publish_advert')
			{
				$response = $this->pep_publish_advert($request);
				
				
				
				
			}
			else if ($method == 'pep_unpublish_advert')
			{
				
			}
			else {
				
				$response = array (
						'id' => $id,
						'result' => NULL,
						'error' => 'unknown method or incorrect parameters'
				);
			}
		} catch (Exception $e) {
			$response = array (
					'id' => $request['id'],
					'result' => NULL,
					'error' => $e->getMessage()
			);
		}

                if ($switched)
		{
			restore_current_blog();
		}

		// output the response
		if (!empty($request['id'])) { // notifications don't want response
			
			echo json_encode($response);
			pep_writelog('creating response'.json_encode($response));
		}
		else {
			pep_writelog('request id is empty');
		}

        
		// finish
		return true;
	}
}
 
$jsonserver = PEPjsonServer::getInstance();

$jsonserver->PerformAction();


?>