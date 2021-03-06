<?php
/**
 * SIL FieldWorks XHTML Importer
 *
 * Imports data from an SIL FLEX XHTML file. The data may come from SIL
 * FieldWorks or other applications.
 *
 * PHP version 5.2
 *
 * LICENSE GPL v2
 *
 * @package WordPress
 * @subpackage Importer
 * @since 3.1
 */

// don't load directly
if ( ! defined('ABSPATH') )
	die( '-1' );
/*
// Check to make sure we can even load an importer.
if ( ! defined( 'WP_LOAD_IMPORTERS' ) )
    return;
*/
// Include the WordPress Importer.
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists('WP_Importer') )  {
    $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
    if ( file_exists( $class_wp_importer ) )
        require_once $class_wp_importer;
}

// One more check.
if ( ! class_exists( 'WP_Importer' ) )
	return;

//============================================================================//

/**
 * Importer class
 */
class sil_pathway_xhtml_Import extends WP_Importer {
	
	public $api;
	
	/*
	 * Table and taxonomy attributes
	 */

	public $search_table_name = SEARCHTABLE;
	public $pos_taxonomy = 'sil_parts_of_speech';
	public $semantic_domains_taxonomy = 'sil_semantic_domains';
	public $writing_system_taxonomy = "sil_writing_systems";

	/*
	 * Relevance level attributes
	 */
	
	public $headword_relevance = 100;
	public $lexeme_form_relevance = 70;
	public $variant_form_relevance = 60;
	public $definition_word_relevance = 50;
	public $semantic_domain_relevance = 40;
	public $sense_crossref_relevance = 30;
	public $custom_field_relevance = 20;
	public $example_sentences_relevance = 10;

	/*
	 * DOM attributes
	 */

	public $dom;
	public $dom_xpath;

	//-----------------------------------------------------------------------------//

	function start()
	{
		/* @todo See if there is a better way to do this than these steps */
		if ( empty ( $_GET['step'] ) )
			$step = 0;
		else
			$step = (int) $_GET['step'];

		$this->header();
		
		switch ($step) {
			/*
			 * First, greet the user and prompt for files.
			 */
			case 0 :
				$this->hello();
				$this->get_user_input();
				echo '</div>';
				break;
			/*
			 * Second, upload and import files
			 */
			case 1 :
				check_admin_referer('import-upload');

				// Get the XMTL file
				$result = $this->upload_files('xhtml');
				if (is_wp_error( $result ))
					echo $result->get_error_message();
				$xhtml_file = $result['file'];			

				// Get the CSS file
				$result = $this->upload_files('css');
				if (is_wp_error( $result ))
					echo $result->get_error_message();
				$css_file = $result['file'];
				?>
				<DIV ID="flushme">no data</DIV>
				<?php
				
				$result = $this->import_xhtml($xhtml_file);
				
				$this->goodbye($xhtml_file, $css_file);
				break;
			/*
			 * for indexing the search strings (configured dictionary)
			 */
			case 2 :
				?>
				<DIV ID="flushme">indexing...</DIV>
				<?php
				$this->index_searchstrings();
				
				$xhtml_file = $result['file'];	
				
				$this->goodbye($xhtml_file, $css_file);
				break;				
			case 3 :
				?>
				<DIV ID="flushme">converting links...</DIV>
				<?php 
				$arrPosts = $this->get_posts("indexed");			

				$entry_counter = 1;
				$entries_count = count($arrPosts);
				
				foreach($arrPosts as $post)
				{
					$doc = new DomDocument();
					$doc->preserveWhiteSpace = false;
					$doc->loadXML($post->post_content);
					$xpath = new DOMXPath($doc);

					$this->import_xhtml_show_progress( $entry_counter, $entries_count, $post->post_title, "Converting Links");
				
					$this->convert_fields_to_links($post->ID, $doc, $xpath);

					$entry_counter++;
				}
					
				echo '<p>' . __( 'Finished!', 'sil_dictionary' ) . '</p>';
				echo '<p>&nbsp;</p>';
				echo '<p>After importing, go to <strong>Tools - <a href="../wp-admin/tools.php?page=sil-dictionary-webonary/include/infrastructure.php">SIL Dictionary</a></strong> to configure more settings.</p>';
			?>
			<?php	
				break;
			}
			$this->footer();
	}

	//-----------------------------------------------------------------------------//

	/**
	 * Greet the user.
	 */
	function hello(){
		echo '<div class="narrow">';
		echo '<p>' . __( 'Howdy! This importer allows you to import SIL FLEX XHTML data into your WordPress site.',
				'sil_dictionary' ) . '</p>';
		echo '<p style="color:red">' . __( 'Please note that the entries will be imported into "Posts". Webonary does not play well together with existing blogs. Articles should be posted under "Pages".',
				'sil_dictionary' ) . '</p>';
	}

	//-----------------------------------------------------------------------------//

	/**
	 * Finish up.
	 */
	function goodbye($xhtml_file, $css_file){		
		
		global $wpdb;

		if(isset($xhtml_file))
		{
			unlink($xhtml_file);
			
			$sql = "DELETE FROM " . $wpdb->prefix . "posts WHERE post_type = 'attachment' AND post_title LIKE '%.xhtml'";
			
			$wpdb->query( $sql );
		}
		
		echo '<div class="narrow">';
				
		if ( $_POST['filetype'] == 'configured') {
			if($_GET['step'] == 1)
			{
				echo '<strong>Next step: </strong>';
				echo '<p>';
					echo '<form enctype="multipart/form-data" id="import-upload-form" method="post" action="' . esc_attr(wp_nonce_url("admin.php?import=pathway-xhtml&amp;step=2", 'import-upload')) . '">';
						echo '<input type="submit" class="button" name="btnIndex" value="Index Search Strings"/>';
						if(isset($_POST['chkConvertToLinks']))
						{
							echo '<input type="hidden" name="chkConvertToLinks" value=' . $_POST['chkConvertToLinks'] .'></input>';
							echo '<input type="hidden" name="filetype" value="configured"></input>';
						}
					echo '</form>';	
				echo '</p>';
			}
			if($_GET['step'] == 2 && $_POST['chkConvertToLinks'] > 0)
			{
				echo '<strong>Next step: </strong>';
				echo '<p>';
					echo '<form enctype="multipart/form-data" id="import-upload-form" method="post" action="' . esc_attr(wp_nonce_url("admin.php?import=pathway-xhtml&amp;step=3", 'import-upload')) . '">';
						echo '<input type="submit" class="button" name="btnIndex" value="Convert Links"/>';
						if(isset($_POST['chkConvertToLinks']))
						{
							echo '<input type="hidden" name="chkConvertToLinks" value=' . $_POST['chkConvertToLinks'] . '></input>';
						}
					echo '</form>';	
				echo '</p>';
			}				
		}
		else
		{
			echo '<p>' . __( 'Finished!', 'sil_dictionary' ) . '</p>';
		}
	}
	//-----------------------------------------------------------------------------//

	/**
	 * Brings up the form to get the files to upload. The code is based on
	 * the function wp_import_upload_form in template.php.
	 *
	 * @since 3.0
	 */
	function getArrFieldQueries($step = 0)
	{
		if($_GET['step'] >= 2 || $step >= 2)
		{
			$querystart = "//span";
		}
		else 
		{
			$querystart = ".//xhtml:span";
		}
		
		//$arrFieldQueries[0] = $querystart . '[@class="headword"]|//*[@class="headword_L2"]|//*[@class="headword-minor"]';
		$arrFieldQueries[0] = $querystart . '[@class="headword"]|./*[@class="headword_L2"]|./*[@class="headword-minor"]';
		$arrFieldQueries[1] = $querystart . '[@class = "headword-sub"]';
		$arrFieldQueries[2] = $querystart . '[contains(@class, "LexemeForm")]';
		//$arrFieldQueries[3] = $querystart . '[@class = "definition"]|//*[@class = "definition_L2"]|//*[@class = "definition-minor"]';
		$arrFieldQueries[3] = $querystart . '[starts-with(@class,"definition")]|' . $querystart . '[starts-with(@class,"LexSense")]';
		//$arrFieldQueries[4] = $querystart . '[@class = "definition-sub"]';
		$arrFieldQueries[4] = $querystart . '[starts-with(@class,"definition-sub")]';
		$arrFieldQueries[5] = $querystart . '[@class = "example"]';
		$arrFieldQueries[6] = $querystart . '[starts-with(@class,"translation")]';
		$arrFieldQueries[7] = $querystart . '[starts-with(@class,"LexEntry-") and not(contains(@class, "LexEntry-publishRoot-DefinitionPub_L2"))]';
		$arrFieldQueries[8] = $querystart . '[@class = "variantref-form"]';
		$arrFieldQueries[9] = $querystart . '[@class = "variantref-form-sub"]';
		$arrFieldQueries[10] = $querystart . '[@class = "sense-crossref"]';

		return $arrFieldQueries;
	}
	
	function get_user_input() {

		$bytes = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
		//multisite cannot handle wp_max_upload_size
		if ( is_multisite() )
		{
			$size = "50MB";
		}
		else
		{
			$size = wp_convert_bytes_to_hr( $bytes );
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) :
			?><div class="error"><p><?php _e('Before you can upload your import file, you will need to fix the following error:'); ?></p>
			<p><strong><?php echo $upload_dir['error']; ?></strong></p></div><?php
		else :
			?>
			<script type="text/javascript">
			function toggleConfigured() {
				document.getElementById("uploadCSS").style.visibility = 'visible';
				document.getElementById("convertToLinks").style.visibility = 'visible';
			}
			function toggleReversal() {			    
			    document.getElementById("uploadCSS").style.visibility = 'hidden';		   
			    document.getElementById("convertToLinks").style.visibility = 'hidden';
			}			
			</script>
			<form enctype="multipart/form-data" id="import-upload-form" method="post" action="<?php echo esc_attr(
				wp_nonce_url("admin.php?import=pathway-xhtml&amp;step=1", 'import-upload')); ?>">
			<p>
				<label for="upload"><?php _e( 'Choose an XHTML file from your computer:' ); ?></label>
					(<?php printf( __('Maximum size: %s' ), $size ); ?>)
			</p>
			<p>
				<input type="file" id="upload" name="xhtml" size="100" />
			</p>
			<div id="uploadCSS">
			<p>
				<label for="upload"><?php _e( 'Choose a CSS file from your computer (optional):' ); ?></label>
					(<?php printf( __('Maximum size: %s' ), $size ); ?>)
			</p>
			<p>
				<input type="file" id="upload" name="css" size="100" />
			</p>
			</div>
			<p>
				<input type="radio" name="filetype" value="configured" onChange="toggleConfigured();" CHECKED/> <?php esc_attr_e('Configured Dictionary'); ?><BR>
				<input type="radio" name="filetype" value="reversal" onChange="toggleReversal();" /> <?php esc_attr_e('Reversal Index'); ?><BR>
				<input type="radio" name="filetype" value="stem" onChange="toggleReversal();" /> *<?php esc_attr_e('Sort Order'); ?> <a href="http://webonary.org/data-transfer/#sortorder" target="_blank">only if sort order is different than configured view</a><BR>				
			</p>
			<div id="convertToLinks">
				<select name="chkConvertToLinks">
					<option value="0"><?php echo esc_attr_e('Don\'t convert items into search links.'); ?></option>
					<option value="1"><?php echo esc_attr_e('Only convert headwords into search links.'); ?></option>
					<option value="2"><?php echo esc_attr_e('Convert all items into search links.'); ?></option>
				</select>
				<?php esc_attr_e('(semantic domains always convert to links).'); ?>
				<br>
				<?php /* 
				<input type="checkbox" name="chkShowDebug"> <?php esc_attr_e('Display debug messages'); ?></input>
				*/ ?>
			</div>
			<p class="submit">
				<input type="submit" class="button" value="<?php esc_attr_e( 'Upload files and import' ); ?>" />
			</p>
			</form>
			<?php
		endif;
	}

	//-----------------------------------------------------------------------------//

	/**
	 * Header for the screen
	 */
	function header() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>' . __( 'Import SIL FLEX XHTML', 'sil_dictionary' ) . '</h2>';
	 }

	//-----------------------------------------------------------------------------//

	/**
	 * Footer for the screen
	 */
	function footer() {
		echo '</div>';
	}

	//-----------------------------------------------------------------------------//

	/**
	 * Upload the files indicated by the user. An override of wp_import_handle_upload.
	 *
	 * @param string $which_file = The file being uploaded
	 * @return array $file = the file, $id = the file's ID
	 */

	// The max file size is determined by the settings in php.ini. upload_max_files is set to 2MB by default
	// in development versions, which is too small for what we do. The setting has been found to be higher
	// in production settings. The post_max_size apparently needs to be at least as big as the
	// upload_max_files setting. If the file size is bigger than the limit, the server simply will not
	// upload it, and there is no indication to the user as to what happened.

	function upload_files( $which_file ) {
		global $wpdb;
		
		if ( !isset($_FILES[$which_file]) ) {
			$file['error'] = __( 'The file is either empty, or uploads are disabled in your php.ini, or post_max_size is defined as smaller than upload_max_filesize in php.ini.' );
			return $file;
		}

		$overrides = array( 'test_form' => false, 'test_type' => false );
		$file = wp_handle_upload( $_FILES[$which_file], $overrides );

		
		if ( isset( $file['error'] ) )
			return $file;

		$url = $file['url'];
		$type = $file['type'];				
		$file = addslashes( $file['file'] );				
		$filename = basename( $file );
		
		$info = pathinfo($file);
		$extension = $info['extension'];
								
		if($extension == "css")
		{
			$upload_dir = wp_upload_dir();
			//$target_path = str_replace('http://' . $_SERVER['HTTP_HOST'], $_SERVER['DOCUMENT_ROOT'], get_bloginfo('template_directory'));
			//$target_path = str_replace('http://' . $_SERVER['HTTP_HOST'], $_SERVER['DOCUMENT_ROOT'], $upload_dir['baseurl']);			
			//$target_path = $target_path . "/imported-with-xhtml.css";
			$target_path = $upload_dir['path'] . "/imported-with-xhtml.css";
			
			//$from_path = $_SERVER['DOCUMENT_ROOT'] . "/wordpress/wp-content/uploads/" . date("Y") . "/" . date("m") . "/" . $filename;
			//$from_path = str_replace('http://' . $_SERVER['HTTP_HOST'], $_SERVER['DOCUMENT_ROOT'], $url);
			
			$from_path = $upload_dir['path'] . "/" . $filename;			
			
			/*
			if(file_exists($target_path))
			{				
				_e('The file imported-with-xhtml.css already exists in your upload folder. If you want to replace it, you have to delete it manually before you import a new file.');									
			}
			*/
			error_reporting(E_ALL);	
			if(copy($from_path, $target_path)) {				
			    _e('The css file has been uploaded into your upload folder and renamed to imported-with-xhtml.css');			
			} else{
			    _e('There was an error uploading the file, please try again!');	
			    echo "<br>";
			    echo "From Path: " . $from_path . "<br>";
			    echo "Target Path: " . $target_path;			    				    
			}				
		}
		
		// Construct the object array
		$object = array( 'post_title' => $filename,
			'post_content' => $url,
			'post_mime_type' => $type,
			'guid' => $url
		);

		// Save the data
		$id = wp_insert_attachment( $object, $file );
		
		if($extension == "css")
		{
			unlink($file);
			
			$sql = "DELETE FROM " . $wpdb->prefix . "posts WHERE post_type = 'attachment' AND post_title LIKE '%." . $extension . "'";
			
			$wpdb->query( $sql );
		}
		
		return array( 'file' => $file, 'id' => $id );
	}

	//-----------------------------------------------------------------------------//

	function index_searchstrings()
	{
		global $wpdb;
		
		$search_table_exists = $wpdb->get_var( "show tables like '$this->search_table_name'" ) == $this->search_table_name;
		$pos_taxonomy_exists = taxonomy_exists( $this->pos_taxonomy );
		$semantic_domains_taxonomy_exists = taxonomy_exists( $this->semantic_domains_taxonomy );
		
		if ( $search_table_exists ) {
			$arrPosts = $this->get_posts();
						
			$subid = 1;
			$sortorder = $wpdb->get_var( "
			SELECT sortorder
			FROM $this->search_table_name
			WHERE relevance >= 95 ORDER BY sortorder DESC LIMIT 0, 1");
						
			if($sortorder == null || $sortorder == 0)
			{
				$sortorder = 1;
			}
			else
			{
				$sortorder++;
			}
			
			$entry_counter = 1;
			$entries_count = count($arrPosts);
						
			foreach($arrPosts as $post)
			{
				$subentry = false;
				if ( $post->ID ){
					$oldSortorder = $wpdb->get_var( "SELECT sortorder FROM $this->search_table_name WHERE relevance >= 95 AND post_id = " . $post->ID . " AND sortorder <> 0");
					
					if(isset($oldSortorder))
					{
						$sortorder = $oldSortorder;
					}
								
					$sql = $wpdb->prepare("DELETE FROM `". $this->search_table_name . "` WHERE post_id = %d", $post->ID);
										
					$wpdb->query( $sql );
					//set as indexed
					$sql = "UPDATE $wpdb->posts SET pinged = 'indexed' WHERE ID = " . $post->ID;
					$wpdb->query( $sql );
				}
							
				$doc = new DomDocument();
				$doc->preserveWhiteSpace = false;
				$doc->loadXML($post->post_content);
				
				$xpath = new DOMXPath($doc);
				$xpath->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');

				if($this->api)
				{
					$step = 2;
				}
				$arrFieldQueries = $this->getArrFieldQueries($step);
				
				$headword = $xpath->query($arrFieldQueries[0])->item(0);
												
				$this->import_xhtml_show_progress( $entry_counter, $entries_count, $post->post_title, "Step 2 of 2: Indexing Search Strings");
								
				if(isset($headword) && $post->post_parent == 0)
				{				
					//import headword
					$this->import_xhtml_search_string($post->ID, $headword, $this->headword_relevance, null, $subid);
					//sub headwords
					$this->import_xhtml_search($doc, $post->ID, $arrFieldQueries[1], ($this->headword_relevance - 5), $subid);
					//lexeme forms
					$this->import_xhtml_search($doc, $post->ID, $arrFieldQueries[2], $this->lexeme_form_relevance);
					//definitions
					$this->import_xhtml_search($doc, $post->ID, $arrFieldQueries[3], $this->definition_word_relevance);
					//sub definitions
					$this->import_xhtml_search($doc, $post->ID, $arrFieldQueries[4], ($this->definition_word_relevance - 5));
					//example sentences
					$this->import_xhtml_search($doc, $post->ID, $arrFieldQueries[5], $this->example_sentences_relevance);
					//Translation of example sentences
					$this->import_xhtml_search($doc, $post->ID, $arrFieldQueries[6], $this->example_sentences_relevance);
					//custom fields
					$this->import_xhtml_search($doc, $post->ID, $arrFieldQueries[7], $this->custom_field_relevance);					
					//variant forms
					$this->import_xhtml_search($doc, $post->ID, $arrFieldQueries[8], $this->variant_form_relevance);
					$this->import_xhtml_search($doc, $post->ID, $arrFieldQueries[9], $this->variant_form_relevance);
					//cross references
					$this->import_xhtml_search($doc, $post->ID, $arrFieldQueries[10], $this->sense_crossref_relevance);
				}
				else
				{
					$subentry = true;
				}
				
				$headword_text = trim($headword->textContent);	
				
				//this is used for the browse view sort order
				$sql = "UPDATE " . $this->search_table_name . " SET sortorder = " . $sortorder . " WHERE search_strings = '" . addslashes($headword_text) . "' COLLATE 'UTF8_BIN' AND relevance >= 95 AND sortorder = 0" ;
				$wpdb->query( $sql );
				
				//this is used for the search sort order
				$sql = "UPDATE " . $wpdb->posts . " SET menu_order = " . $sortorder . " WHERE post_title = '" . addslashes($headword_text) . "' collate utf8_bin AND menu_order = 0";
				$wpdb->query( $sql );
				
			
				/*
				 * Load semantic domains
				 */
				if ( $semantic_domains_taxonomy_exists )
				{
					$this->import_xhtml_semantic_domain($doc, $post->ID, $subentry, false);
					$this->import_xhtml_semantic_domain($doc, $post->ID, $subentry, true);
				}
				/*
				 * Load parts of speech (POS)
				 */
				if ( $pos_taxonomy_exists )
					$this->import_xhtml_part_of_speech($doc, $post->ID);
				
				$subid++;
				$entry_counter++;
				$sortorder++;
			}
		}
	}
	
	/**
	 * Import the XHTML data
	 *
	 * @return <type>
	 */
	function import_xhtml( $xhtml_file, $api = false ) {
		global $wpdb;
		
		$this->api = $api;
		
		// Some of these variables could eventually become user options.
		$this->dom = new DOMDocument('1.0', 'utf-8');
		if($api)
		{	
			$this->dom->preserveWhiteSpace = false;
			$this->dom->loadXML($xhtml_file);
		}
		else 
		{
			$xhtml_file = realpath($xhtml_file);
			
			$ret_val = $this->dom->load($xhtml_file);
		}

		$this->dom_xpath = new DOMXPath($this->dom);
		$this->dom_xpath->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');
		
		/*
		 * 
		 * Load the Writing Systems (Languages)
		 */
		if ( taxonomy_exists( $this->writing_system_taxonomy ) )
			$this->import_xhtml_writing_systems();
		/*
		 * Import
		 */
		if ( $_POST['filetype'] == 'configured' || $api == true) {
			//  Make sure we're not working on a reversal file.
			$reversals = $this->dom_xpath->query( '(//xhtml:span[contains(@class, "reversal-form")])[1]' );
			if ( $reversals->length > 0 )
				return;
			//inform the user about which fields are available
			$arrFieldQueries = $this->getArrFieldQueries();
			foreach($arrFieldQueries as $fieldQuery)
			{			
				$fields = $this->dom_xpath->query($fieldQuery);
				if($fields->length == 0 && isset($_POST['chkShowDebug']))
				{
					echo "No entries found for the query " . $fieldQuery . "<br>";
				}
			}
			$this->import_xhtml_entries();
		}
		elseif ( $_POST['filetype'] == 'reversal')
			$this->import_xhtml_reversal_indexes();
		elseif ( $_POST['filetype'] == 'stem')
			$this->import_xhtml_stem_indexes();
		return;
	} // function import_xhtml($xhtml_file)

	//-----------------------------------------------------------------------------//

	/**
	 * Import the writing systems (languages)
	 * @global <type> $wpdb
	 */

	// Currently we aren't deleting any existing writing systems.
	// For the moment, any bad writing systems must be removed by hand.
	function import_xhtml_writing_systems () {
		global $wpdb;

		// An example of writing system and font in meta of the XHTML file header:
		// <meta name="en" content="English" scheme="Language Name" />
		// <meta name="en" content="Times New Roman" scheme="Default Font" />
		$writing_systems = $this->dom_xpath->query( '//xhtml:meta[@scheme = "Language Name"]|//xhtml:meta[@name = "DC.language"]' );
		// Currently we aren't using font info.
		// $writing_system_fonts = $this->dom_xpath->query( '//xhtml:meta[@scheme = "Default Font"]' );
		if($writing_systems->length == 0 && isset($_POST['chkShowDebug']))
		{
			echo "The language names were not found. Please add the language name meta tag in your xhtml file.<br>";
		}
		foreach ( $writing_systems as $writing_system ) {
			$writing_system_abbreviation = $writing_system->getAttribute( "name");
			$writing_system_name = $writing_system->getAttribute( "content");
			
			if($writing_system->getAttribute( "name") == "DC.language")
			{
				$content = explode(":", $writing_system->getAttribute( "content"));
				$writing_system_abbreviation = $content[0];
				$writing_system_name = $content[1];
			}
			
			// Currently we aren't using font info.
			//$writing_system_font = $this->dom_xpath->query(
			//  '../xhtml:meta[@name = "' . $writing_system_abbreviation . '" and @scheme = "Default Font"]',
			//  $writing_system );
			//$font = $writing_system_font->item( 0 )->getAttribute( "content" );

			wp_insert_term(
				$writing_system_name,
				$this->writing_system_taxonomy,
				array(
					'description' => $writing_system_name,
					'slug' => $writing_system_abbreviation
				));

			// We are not using this taxonomy to group posts, but rather to search for strings
			// with a given writing system. If we ever change that, we'll want to load this on
			// a post by post basis.
			//
			//wp_set_object_terms( $post_id, $writing_system_name, $writing_systems_taxonomy );

		} // foreach ( $writing_systems as $writing_system ) {

		// Since we're not associating this taxonomy with any posts, wp_term_taxonomy.count = 0.
		// When that's true, the taxonomy doesn't work correctly in the drop down list. The
		// field needs a count of at least 1. I'm filling the number with something bigger
		// so that it looks more obviously like a dummy number.

		$sql = $wpdb->prepare("UPDATE $wpdb->term_taxonomy SET COUNT = 999999 WHERE taxonomy = '%s'", $this->writing_system_taxonomy );
		$wpdb->query( $sql );
	}

	//-----------------------------------------------------------------------------//

	/**
	 * Import entries for the Configured Dictionary.
	 * @global <type> $wpdb
	 */
	function import_xhtml_entries () {
		global $wpdb;

		/*
		 * Loop through the entries so we can post them to WordPress.
		 */

		//the query looks for the spans with the headword and returns their parent <div class="entry">
		$entries = $this->dom_xpath->query('//xhtml:span[@class="headword"]/..|//xhtml:span[@class="headword_L2"]/..|//xhtml:span[@class="headword-minor"]/..|//xhtml:span[@class="headword-sub"]/..');
		$entries_count = $entries->length;
		
		if($entries->length == 0) 
		{				
			echo "<div style=color:red>ERROR: No headwords found.</div><br>";
			return;
		}
		
		$entry_counter = 1;
		foreach ( $entries as $entry ){
			// Find the headword. Should be only 1 headword at most. The
			// $headword->textContent picks up the value of both the headword and
			// the homograph number. This is presumably because the XML DOM
			// textContent property "returns the value of all text nodes
			// within the element node." The XHTML for an entry with homograph
			// number looks like this:
			// <span class="headword" lang="ii">my headword<span class="xhomographnumber">1</span></span>
			$entry = $this->convert_fieldworks_images_to_wordpress($entry);
			$entry = $this->convert_fieldworks_audio_to_wordpress($entry);
			
			$entry_xml = $this->dom->saveXML( $entry );
			
			$headwords = $this->dom_xpath->query( './xhtml:span[@class="headword"]|./xhtml:span[@class="headword_L2"]|./xhtml:span[@class="headword-minor"]|./*[@class="headword-sub"]', $entry );
			
			//$headword = $headwords->item( 0 )->nodeValue;
			foreach ( $headwords as $headword ) {
				$headword_language = $headword->getAttribute( "lang" );
	
				$entry = $this->convert_homographs($entry, "xhomographnumber");
				
				$headword_text = $headword->textContent;
				
				$flexid = $entry->getAttribute("id");
								
				if(strlen(trim($flexid)) == 0)
				{
					$flexid = $headword_text;
				}

				$entry_xml = $this->dom->saveXML($entry, LIBXML_NOEMPTYTAG);	
				
				$entry_xml = addslashes($entry_xml);
				$entry_xml = stripslashes($entry_xml);
				//$entry_xml = str_replace("'","&#39;",$entry_xml);
				
				$post_parent = 0;
				if (!preg_match("/class=\"entry\"/i", $entry_xml) && !preg_match("/class=\"headword-minor\"/i", $entry_xml))
				{
					$post_parent = 1;
					$entry_xml = str_replace("class=\"subentry\"","class=\"entry\"",$entry_xml);
					$entry_xml = str_replace("class=\"headword-sub\"","class=\"headword\"",$entry_xml);
				}
				/*
				 * Insert the new entry into wp_posts
				 */
								
				//$post_id = $this->get_post_id( $flexid );
				//$post_id = $this->get_post_id_bytitle( $headword_text, $headword_language, $subid, true);
				$post_id = $wpdb->get_var("SELECT ID FROM " . $wpdb->posts . " WHERE post_title = '" . addslashes(trim($headword_text)) . "' collate utf8_bin");
				$post_id_exists = $post_id != NULL;

				//$post_id = wp_insert_post( $post );
				
				if($post_id == NULL)
				{
					$sql = $wpdb->prepare(		
					"INSERT INTO ". $wpdb->posts . " (post_title, post_content, post_status, post_parent, post_name, comment_status)
					VALUES ('%s', '%s', 'publish', %d, '%s', '%s')",
					trim($headword_text), $entry_xml, $post_parent, $flexid, get_option('default_comment_status') );
										
					$wpdb->query( $sql );
					
					$post_id = mysql_insert_id();
					if($post_id == 0)
					{
						$post_id = $wpdb->get_var("SELECT ID FROM " . $wpdb->posts . " WHERE post_title = '" . addslashes(trim($headword_text)) . "'");
					}					
						
					wp_set_object_terms( $post_id, "webonary", 'category' );
				}
				else
				{
					$sql = $wpdb->prepare(
					"UPDATE " . $wpdb->posts . " SET post_title = '%s', post_content = '%s', post_status = 'publish', pinged='', post_parent=%d, post_name='%s', comment_status='%s' WHERE ID = %d", 
					trim($headword_text), $entry_xml, $post_parent, $flexid, get_option('default_comment_status'), $post_id);

					$wpdb->query( $sql );
				}
				
				//print_r($wpdb->queries);
				
				/*
				 * Show progresss to the user.
				 */
				$this->import_xhtml_show_progress( $entry_counter, $entries_count, $headword_text, "Step 1 of 2: Importing Post Entries" );
			} // foreach ( $headwords as $headword )
			
			$entry_counter++;
		} // foreach ($entries as $entry){

		if($entries->length > 0)
		{
			$this->convert_fieldworks_links_to_wordpress();
		}
	}

	//-----------------------------------------------------------------------------//


	function convert_fieldworks_audio_to_wordpress ($entry) {
		global $wpdb;

		$upload_dir = wp_upload_dir();
		
		// audio example:
		//<a class="audioButton" href="/files/audio/sprache.mp3"></a>

		//<span class="LexEntry-publishStemPara-Audio"><span lang="trc-Zxxx-x-audio" xml:space="preserve">634962856425589029a√É¬± doj.wav</span><span lang="en" xml:space="preserve"> </span></span>
		$audios = $this->dom_xpath->query('.//xhtml:span[contains(@class, "Audio")]|.//xhtml:span[contains(@class, "audio")]', $entry);

		foreach ( $audios as $audio ) {
					
			if(strlen(trim($audio->textContent)) > 0)
			{
				$audiofiles = explode(";", $audio->textContent);
				$spanelement = $this->dom->createElement('span');
				foreach($audiofiles as $audiofile)
				{
					
					$filename = "/audio/" . str_replace("\\", "/", trim($audiofile));
					if (!file_exists($upload_dir['path'] . $filename)) 
					{
						echo "file " .  $upload_dir['baseurl'] . $filename . " doesn't exist<br>";
					}
					
					$newimage = $this->dom->createElement('img');
					$newimage->setAttribute("src", get_bloginfo('wpurl') . "/wp-content/plugins/sil-dictionary-webonary/audiolibs/img/blank.gif");
					
					$newelement = $this->dom->createElement('a');
					//$newelement->appendChild($this->dom->createTextNode(""));
					$newelement->appendChild($newimage);
					$newelement->setAttribute("class", "audioButton");
					$newelement->setAttribute("href",  $upload_dir['baseurl'] . $filename);
					
					$spanelement->appendChild($newelement);
				}
				$parent = $audio->parentNode;	
				$parent->replaceChild($spanelement, $audio);
			}									
		} // foreach ( $audios as $audio )
		return $entry;
	}
			
	function convert_fieldworks_images_to_wordpress ($entry) {
		global $wpdb;

		// image example (with link):
		//<a href="javascript:openImage('mouse.png')"><img src="wp-content/uploads/images/thumbnail/mouse.png" /></a>

		$images = $this->dom_xpath->query('//xhtml:img', $entry);

		foreach ( $images as $image ) {
			
			$src = $image->getAttribute( "src" );
			$upload_dir = wp_upload_dir();			
			$replaced_src = str_ireplace("pictures/", $upload_dir['baseurl'] . "/images/thumbnail/", $src);
			$pic = str_ireplace("pictures/", "", $src);
			
			$newimage = $this->dom->createElement('img');
			$newimage->setAttribute("src", $replaced_src);
			
			$newelement = $this->dom->createElement('a');
			$newelement->appendChild($newimage);
			$newelement->setAttribute("class", "image");
			$newelement->setAttribute("href",  $upload_dir['baseurl'] . "/images/original/" . $pic);
			$parent = $image->parentNode;	
			$parent->replaceChild($newelement, $image);
						
			//error_log("IMAGE: " . $replaced_src);
									
		} // foreach ( $images as $image )

		return $entry;
	} // function convert_fieldworks_images_to_wordpress()
	
	
	/**
	 * Convert links exported by the FLEx Configured Dictionary Export into
	 * links that WordPress understands, such as http://localhost/?p=61151.
	 * In this case, 61151 is the ID in wp_posts for the entry.
	 * @global $wpdb
	 */

	function convert_fieldworks_links_to_wordpress () {
		global $wpdb;

		// link example:
		//		<a href="#hvo14216">
		
		$arrPosts = $this->get_posts();

		$entrycount = 0;
		foreach($arrPosts as $post)
		{
		
			$doc = new DomDocument();
			$doc->preserveWhiteSpace = false;
			$doc->loadXML($post->post_content);
						
			$xpath = new DOMXPath($doc);
					
			$links = $xpath->query('//a');
									
			//$links = $this->dom_xpath->query('//xhtml:a');
			$totalLinks = $links->length;
			if($totalLinks > 0)
			{
				foreach ( $links as $link ) {
					if(strtolower($link->getAttribute("class")) != "audiobutton" && strtolower($link->getAttribute("class")) != "image")
					{
						// Get the target hvo link to replace
						$href = $link->getAttribute( "href" );
						$hvo = substr($href, 4);
			
						// Now get the cross reference. Should only be one, but written to
						// handle more if they come along.
						$cross_refs = $xpath->query( '//span[contains(@class,"crossref")]|.//*[contains(@class,"HeadWordRef")]', $link );
						//$cross_refs = $this->dom_xpath->query( './/xhtml:span[contains(@class,"crossref")]|.//*[contains(@class,"HeadWordRef")]', $link );
						
						foreach ( $cross_refs as $cross_ref ) {
			
							$sensenumbers = $xpath->query('//span[@class="xsensenumber"]', $cross_ref);
							//$sensenumbers = $this->dom_xpath->query('//xhtml:span[@class="xsensenumber"]', $cross_ref);			
							foreach($sensenumbers as $sensenumber)
							{
								$sensenumber->parentNode->removeChild($sensenumber);
							}
										
							// Get the WordPress post ID for the link.
							$flexid = str_replace("#", "", $href);				
							$post_id = (string) $this->get_post_id( $flexid );
			
							// Now replace the link to hvo wherever it appears with a link to
							// WordPress ID The update command should look like this:
							// UPDATE `nuosu`.`wp_posts` SET post_content =
							//	REPLACE(post_content, 'href="#hvo14216"', 'href="index.php?p=61151"');
							//if ( empty( $post_id ) )
								//$post_id = 'id-not-found';
							$sql = "UPDATE $wpdb->posts SET post_content = ";
							$sql = $sql . "REPLACE(post_content, 'href=";
							$sql = $sql . '"' . $href . '"';
							$sql = $sql . "', 'href=";
							$sql = $sql . '"';
							if ( empty( $post_id ) )
							{
								$sql = $sql . "?s=" . addslashes($link->textContent) . "&amp;partialsearch=1";
							}
							else 
							{
								$sql = $sql . "?p=" . $post_id;
							}
							$sql = $sql . '"';
							$sql = $sql . "') " .
							" WHERE ID = " . $post->ID;
						
							$wpdb->query( $sql );
							
						} // foreach ( $cross_refs as $cross_ref )
					}
				} // foreach ( $links as $link )
			}
			$entrycount++;
			$this->import_xhtml_show_progress($entrycount, count($arrPosts), "", "Step 1 of 2: Please wait... converting FLEx links for Wordpress.");
			
		} //foreach $arrPosts as $post
	} // function convert_fieldworks_links_to_wordpress()

	function convert_fields_to_links($post_id, $entry, $xpath) {
		
		global $wpdb;
		
		$sql = "UPDATE $wpdb->posts SET pinged = 'linksconverted' WHERE ID = " . $post_id;
		$wpdb->query( $sql );
		
		$arrAllQueries = $this->getArrFieldQueries(true);
		
		if($_POST['chkConvertToLinks'] == 1)
		{
			$arrFieldQueries[0] = $arrAllQueries[0];
			$arrFieldQueries[1] = $arrAllQueries[1];
		}
		else 
		{
			$arrFieldQueries = $arrAllQueries;
		}
		
		foreach($arrFieldQueries as $fieldQuery)
		{			
			if (!preg_match("/sense-crossref/i", $fieldQuery))
			{
				$fields = $xpath->query($fieldQuery);							
			
				foreach($fields as $field)
				{
					$Emphasized_Text = null;
					$searchstring = $field->textContent;
					if(is_numeric(substr($searchstring, (strlen($searchstring) - 1), 1)))
					{
						$searchstring = substr($searchstring, 0, (strlen($searchstring) - 1));
					}		
							
					if($field->getAttribute("class") == "definition" || $field->getAttribute("class") == "definition-sub")
					{
						$Emphasized_Text = $xpath->query('//span[@class = "Emphasized_Text"]');
						
						if($Emphasized_Text->length > 0)
						{
							if($field->getAttribute("class") == "definition-sub")
							{
								$newField = $xpath->query('//span[@class="definition-sub"]/node()[not(@class = "Emphasized_Text")]');
							}
							else 
							{
								$newField = $xpath->query('//span[@class="definition"]/node()[not(@class = "Emphasized_Text")]');
							}
							
							$field = $newField->item(0);
							$searchstring = $field->textContent;
						}
					}				

					//if($Emphasized_Text->length == 0)
					if($field->getAttribute("class") != "partofspeech")
					{
						//$newelement = $this->dom->createElement('a');
						$newelement = $entry->createElement('a');
						//$newelement->appendChild($this->dom->createTextNode(addslashes(trim($field->textContent))));
						$newelement->appendChild($entry->createTextNode(addslashes($field->textContent)));
						$newelement->setAttribute("href", "?s=" . addslashes(trim($searchstring)) . "&partialsearch=1");
						if($Emphasized_Text->length > 0)
						{
							$newelement->setAttribute("class", "definition");
						}
						else
						{
							$newelement->setAttribute("class", $field->getAttribute("class"));
						}
						$newelement->setAttribute("lang", $field->getAttribute("lang"));
						/*
						if($Emphasized_Text->length > 0)
						{							
							$Emphasized_Text->item(0)->insertBefore($newelement);
							$newelement = $Emphasized_Text->item(0);				
						}
						*/
						$parent = $field->parentNode;	
						$parent->replaceChild($newelement, $field);		
					}
				}
			}
		}

		$entry_xml = $entry->saveXML( $entry );
						
		$sql = "UPDATE $wpdb->posts " .
		" SET post_content = '" . addslashes(stripslashes($entry_xml)) . "'" . 
		" WHERE ID = " . $post_id;
				
		$wpdb->query( $sql );
	}	
	         
	function convert_homographs($entry, $classname)
	{
		$arrHomographs = $this->dom_xpath->query( './/xhtml:span[@class="' . $classname . '"]', $entry );
		foreach($arrHomographs as $homograph)
		{
			$numbers = array("1", "2", "3", "4", "5");
			$homographs = array("₁", "₂", "₃", "₄", "₅");
			
			$newHomograph = str_replace($numbers, $homographs, $homograph->textContent);
			
			$newNode = $this->dom->createElement('span', $newHomograph);
			$newNode->setAttribute('class', 'xhomographnumber');

			// fetch and replace the old element
			//$oldNode = $dom->getElementById('old_div');
			$parent = $homograph->parentNode;	
			$parent->replaceChild($newNode, $homograph);	
		}
		
		return $entry;
	}
	function convert_semantic_domains_to_links($post_id, $doc, $field, $termid) {
		global $wpdb;

		$newelement = $doc->createElement('a');
		$newelement->appendChild($doc->createTextNode(addslashes(trim($field->textContent))));		
		$newelement->setAttribute("href", "?s=&partialsearch=1&tax=" . $termid);
		$newelement->setAttribute("class", $field->getAttribute("class"));
		$parent = $field->parentNode;			
		$parent->replaceChild($newelement, $field);
		
		$entry_xml = $doc->saveXML( $entry );
		
		$sql = "UPDATE $wpdb->posts " .
		" SET post_content = '" . addslashes($entry_xml) . "'" . 
		" WHERE ID = " . $post_id;
						 		
		$wpdb->query( $sql );
		
		return $entry;
	}

	//-----------------------------------------------------------------------------//

	/**
	 * Show progress to the user
	 * @param <type> $entry_counter = current entry number
	 * @param <type> $entries_count = total number of entries
	 * @param <type> $headword_text = text of the headword
	 */
	function import_xhtml_show_progress( $entry_counter, $entries_count, $headword_text, $msg = "" ) {		
		flush();
		if($this->api)
		{
			if($entry_counter == 1)
			{
				echo $msg . "\n";
			}
			echo $entry_counter . " of " . $entries_count . " entries: " . $headword_text . "\n";
		}
		else 
		{
		?>
			<SCRIPT type="text/javascript">//<![CDATA[
			d = document.getElementById("flushme");
			info = "<strong><?php echo $msg; ?></strong><br>";
			<?php
			if($entries_count >= 1)
			{
			?>
				info += "<?php echo $entry_counter; ?> of <?php echo $entries_count; ?> entries: <?php  echo $headword_text; ?>";
			<?php 
			}
			?>
			//info += "<br>";
			//info += "Memory Usage: <?php echo memory_get_usage() . " bytes"; ?>";
			
			d.innerHTML = info;
			//]]></SCRIPT>
		<?php	
		}	
	}

	//-----------------------------------------------------------------------------//

	/**
	 * Load the search table for the entry.
	 * @param <type> $entry = XHTML of the dictionary entry
	 * @param <type> $post_id = ID of the WordPress post.
	 * @param <type> $query = the xhtml query
	 * @param <type> $relevance = weighted importance of this particular string for search results 
	 */
	function import_xhtml_search( $doc, $post_id, $query, $relevance, $subid = 0 ) {

		if($relevance == ($this->headword_relevance - 5))
		{
			$subid++;
		}
		//$fields = $this->dom_xpath->query( $query, $entry );

		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');
				
		$fields = $xpath->query($query);
		
		foreach ( $fields as $field ) {

			$this->import_xhtml_search_string($post_id, $field, $relevance, null, $subid);
			if($subid > 0)
			{
				$subid++;
			}
		}				
				
	}	

	//-----------------------------------------------------------------------------//

	/**
	 * Utility function to store off the search string
	 * @param <type> $table = table holding the search strings
	 * @param <type> $post_id = ID of post in wp_posts
	 * @param <type> $language_code = Should be ISO 639-3, but can be longer
	 * @param <type> $search_string = string we want to search for in the post
	 * @param <int> $relevance = weighted importance of this particular string for search results
	 */
	function import_xhtml_search_string( $post_id, $field, $relevance, $mySearch_string = null, $subid = 0) {
		global $wpdb;
		
		$language_code = $field->getAttribute("lang");
		
		if(isset($mySearch_string))
		{
			$search_string = $mySearch_string;
		}
		else
		{
			$search_string = $field->textContent;
		}
							
		// We're using a generic $wpdb->query instead of a $wpdb->insert
		// to make use of the ON DUPLICATE KEY feature of MySQL.

		// $wdbt->prepare likes to add single quotes around string replacements,
		// and that's why I concatenated the table name.			
		$sql = $wpdb->prepare(		
			"INSERT INTO `". $this->search_table_name . "` (post_id, language_code, search_strings, relevance, subid)
			VALUES (%d, '%s', '%s', %d, %d)",
			$post_id, $language_code, trim($search_string), $relevance, $subid );
			
			//ON DUPLICATE KEY UPDATE search_strings = CONCAT(search_strings, ' ',  '%s');",			

			$wpdb->query( $sql );
			
		//this replaces the special apostroph with the standard apostroph
		//the first time round the special apostroph is inserted, so that both searches are valid
		if(strstr($search_string,"’"))
		{
			$mySearch_string = str_replace("’", "'", $search_string);
			$this->import_xhtml_search_string( $post_id, $field, $relevance, $mySearch_string, $subid);
		}
	}

	
	//-----------------------------------------------------------------------------//

	function get_category_id() {
	global $wpdb;
	
	$catid = $wpdb->get_var( "
		SELECT term_id
		FROM $wpdb->terms
		WHERE name LIKE 'webonary'");	
	
	return $catid;
	}
	
	function get_duplicate($postid, $searchstring, $relevance, $lang) {
		global $wpdb;
		
		$isDuplicate = false;
		
		$duplicatePostId = $wpdb->get_var( "
			SELECT post_id
			FROM $this->search_table_name
			WHERE post_id = " . $postid . " AND search_strings = '" . addslashes($searchstring) . 
			"' AND relevance = ". $relevance . " AND language_code = '" . $lang . "'");	
		
		if($duplicatePostId == $postid)
		{
			$isDuplicate = true;
		}
		
		return $isDuplicate;
	}
	
	function get_posts($index = "") {
		global $wpdb;

		// @todo: If $headword_text has a double quote in it, this
		// will probably fail.
		$sql = "SELECT ID, post_title, post_content, post_parent
			FROM $wpdb->posts
			INNER JOIN " . $wpdb->prefix . "term_relationships ON object_id = ID
			WHERE " . $wpdb->prefix . "term_relationships.term_taxonomy_id = " . $this->get_category_id(); 
		//using pinged field for not yet indexed
		$sql .= " AND post_status = 'publish' AND pinged = '" . $index . "'";
				
		return $wpdb->get_results($sql);
	}	
		
	/**
	 * Utility function return the post ID given a headword.
	 * @param string $headword = headword to find
	 * @return int = post ID
	 */

	function get_post_id( $flexid ) {
		global $wpdb;
		
		$sql = "SELECT id
			FROM $wpdb->posts
			WHERE post_name = '" . trim($flexid) . "'	collate utf8_bin AND post_status = 'publish'";
		
		$post_id = $wpdb->get_var($sql);
		
		return $post_id;
	}
	
	function get_post_id_bytitle( $headword, $langcode, &$subid, $isLangCode = false ) {
		global $wpdb;

		// @todo: If $headword_text has a double quote in it, this
		// will probably fail.
		$sql = "SELECT post_id, subid
			FROM $this->search_table_name
			WHERE search_strings = '" . addslashes(trim($headword)) . "' collate utf8_bin AND relevance >= 95";
		if($isLangCode)
		{
			$sql .= " AND language_code = '" . $langcode . "'";			
		}
		else 
		{
			$sql .= " AND language_code <> '" . $langcode . "'";
		}
					
		$row = $wpdb->get_row( $sql );
		
		$postid = 0;
		if(count($row) > 0)
		{
			$subid = $row->subid;
			$postid = $row->post_id;
		}
		return $postid;
	}	

	//-----------------------------------------------------------------------------//

	/**
	 * Import the part(s) of speech (POS) for an entry.
	 * @param <type> $entry = XHTML of the dictionary entry
	 * @param <type> $post_id = ID of the WordPress post.
	 */

	// Currently we aren't deleting any existing POS terms. More than one post may
	// refer to a domain. For the moment, any bad POSs must be removed by hand.

	function import_xhtml_part_of_speech( $doc, $post_id ) {

		$xpath = new DOMXPath($doc);
		
		$pos_terms = $xpath->query('//span[contains(@class, "partofspeech")]');
		
		$i = 0;
		//$parent_term_id = 0;
		foreach ( $pos_terms as $pos_term ) {
			$pos_name = (strlen($pos_term->textContent) > 30) ? substr($pos_term->textContent, 0, 30) . '...' : $pos_term->textContent;

			wp_insert_term(
				$pos_name,
				$this->pos_taxonomy,
				array(
					'description' => $pos_name,
					'slug' => $pos_name
				)
			);
			
			wp_set_object_terms( $post_id, $pos_name, $this->pos_taxonomy, true);			
		}
	}

	//-----------------------------------------------------------------------------//

	/**
	 * Import the semantic domain(s) for the entry.
	 * @param <type> $entry = XHTML of the dictionary entry
	 * @param <type> $post_id = ID of the WordPress post.
	 */

	// Currently we aren't deleting any existing semantic domains. More than one post may
	// refer to a domain. For the moment, any bad domains must be removed by hand.

	function import_xhtml_semantic_domain( $doc, $post_id, $subentry, $convertToLinks) {

		global $wpdb;
		$xpath = new DOMXPath($doc);
		
		if($subentry)
		{
			$semantic_domains = $xpath->query('//span[@class = "semantic-domains-sub"]//span[@class = "semantic-domain-name-sub"]');			
		}
		else
		{
			//$semantic_domain_terms = $xpath->query('//span[@class = "semantic-domains"]//span[starts-with(@class, "semantic-domain-name")]');
			$semantic_domains = $xpath->query('//span[@class = "semantic-domains"]');
		}

		$i = 0;
		foreach ( $semantic_domains as $semantic_domain ) {
			$sd_names = $xpath->query('//span[@class = "semantic-domains"]//span[starts-with(@class, "semantic-domain-name")]', $semantic_domain);
			$sd_numbers = $xpath->query('//span[@class = "semantic-domains"]//span[starts-with(@class, "semantic-domain-abbr")]//span', $semantic_domain);
						
			$sc = 0;
			foreach($sd_names as $sd_name)
			{
				//echo $sd_numbers->item($sc)->textContent . " " . $sd_name->textContent . " " . $sd_name->getAttribute("lang") . "<br>";
				$semantic_domain_language = $sd_name->getAttribute("lang");
				$domain_name = str_replace("]", "", $sd_name->textContent);
				$sd_number_text = str_replace("[", "", $sd_numbers->item($sc)->textContent);
				$domain_class = $sd_name->getAttribute("class");
							
				$arrTerm = wp_insert_term(
					$domain_name,
					$this->semantic_domains_taxonomy,
					array(
						'description' => trim($domain_name),
						'slug' => $sd_number_text 
					));		
														
					$termid = $wpdb->get_var( "
						SELECT term_id
						FROM $wpdb->terms
						WHERE slug = '" . str_replace(".", "-", $sd_number_text) . "'");
										
				if($termid == NULL || $termid == 0)	
				{	
					if (array_key_exists('term_id', $arrTerm))
					{
						$termid = $arrTerm['term_id'];
						$terms[$i] = $termid; 
						$i++;
					}
				}
				
				$this->convert_semantic_domains_to_links($post_id, $doc, $sd_name, $termid);
				
				if(isset($termid))
				{
					$wpdb->query( "INSERT INTO $wpdb->term_relationships (object_id, term_taxonomy_id) VALUES (" . $post_id . ", " . $termid . ") ON DUPLICATE KEY UPDATE term_order = VALUES(term_order)" );
				}
				/*			
				if($convertToLinks == true)
				{
					$this->convert_semantic_domains_to_links($post_id, $doc, $sd_name, $termid);			
				}
				else
				{
					$x = 0;
					wp_set_object_terms( $post_id, $domain_name, $this->semantic_domains_taxonomy, true );
				}
				*/
				$arrTerm = null;
				
				$sc++;
			}
		}
		
		update_option("hasSemDomainNumbers", 1);

		$sql = $wpdb->query("UPDATE $wpdb->term_taxonomy SET COUNT = 1 WHERE taxonomy = 'sil_semantic_domains'");
		
	}

	//-----------------------------------------------------------------------------//

	/**
	 * Import reversal indexes from a reversal index XHTML file. This will
	 * not add any new lexical entries, but it will make entries in the search
	 * table.
	 */

	function import_xhtml_reversal_indexes() {

		$entries = $this->dom_xpath->query('//xhtml:div[@class="entry"]');
		$entries_count = $entries->length;
		$entry_counter = 1;
		foreach ( $entries as $entry ){
								
			if(strlen(trim($entry->textContent)) == 0)
			{
				$entry_counter++;
				continue;	
			}
			/*
			 * Show progresss to the user.
			 */
			$this->import_xhtml_show_progress( $entry_counter, $entries_count, $headword_text );

			/*
			 * Reversals
			 */

			// Should be only 1 reversal at most per entry.
			$reversals = $this->dom_xpath->query( './xhtml:span[contains(@class, "reversal-form")]', $entry );
			$reversal_language = $reversals->item(0)->getAttribute( "lang" );
			$reversal_text = $reversals->item(0)->textContent;
			
			//$headwords = $this->dom_xpath->query('./xhtml:span[@class = "senses"]/xhtml:span[@class = "sense"]/xhtml:span[@class = "headword"]|./xhtml:span[@class = "senses"]/xhtml:span[starts-with(@class, "headref")]', $entry );
			$headwords = $this->dom_xpath->query('.//xhtml:span[@class = "headword"]|.//xhtml:span[starts-with(@class, "headref")]', $entry );
			
			foreach ( $headwords as $headword ) {
				
				$entry = $this->convert_homographs($entry, "Homograph-Number");
				
				//the Sense-Reference-Number doesn't exist in search_strings field, so in order for it not to be searched, it has to be removed
				$sensereferences = $this->dom_xpath->query('//xhtml:span[@class="Sense-Reference-Number"]', $headword);			
				foreach($sensereferences as $sensereference)
				{
					$sensereference->parentNode->removeChild($sensereference);
				}
												
				$headword_text = trim($headword->textContent);		
				
				$post_id = $this->get_post_id_bytitle( $headword_text, $reversal_language, $subid);
				
				if ( $post_id != NULL ) {
					$this->import_xhtml_search_string( $post_id, $reversals->item(0), $this->headword_relevance, null, $subid);
				}
				else
				{
					echo "PostId for '" . $headword_text . "' not found.<br>";
				} 
			}
			$entry_counter++;
		} // foreach ( $entries as $entry )
	}

/**
	 * Import stem indexes from a stem view index XHTML file. This will
	 * not add any new lexical entries, but it will update the field "sortorder" in the search
	 * table.
	 */

	function import_xhtml_stem_indexes() {

		global $wpdb;
		
		//$entries = $this->dom_xpath->query('//xhtml:div[@class="entry"]');
		$entries = $this->dom_xpath->query('//xhtml:span[@class="headword"]|//xhtml:span[@class="headword-minor"]|//xhtml:span[@class="headword-sub"]');
		$entries_count = $entries->length;
		$entry_counter = 1;
		foreach ( $entries as $entry ){

			$entry = $this->convert_homographs($entry, "xhomographnumber");
						
			$headword_text = trim($entry->textContent);	

			//this is used for the browse view sort order
			$sql = "UPDATE " . $this->search_table_name . " SET sortorder = " . $entry_counter . " WHERE search_strings = '" . addslashes($headword_text) . "' COLLATE 'UTF8_BIN' AND relevance >= 95" ;
			$wpdb->query( $sql );
			
			//this is used for the search sort order
			$sql = "UPDATE " . $wpdb->posts . " SET menu_order = " . $entry_counter . " WHERE post_title = '" . addslashes($headword_text) . "' collate utf8_bin";
			$wpdb->query( $sql );

			/*
			 * Show progresss to the user.
			 */			
			$this->import_xhtml_show_progress( $entry_counter, $entries_count, $headword_text );

			$entry_counter++;
		} // foreach ( $entries as $entry )
		
	}
	
	//-----------------------------------------------------------------------------//

	function sil_pathway_xhtml_Import()
	{
		/**
		 * Empty function
		 */
	}
} // class

//===================================================================================//


/*
 * Register the importer so WordPress knows it exists. Specify the start
 * function as an entry point. Paramaters: $id, $name, $description,
 * $callback.
 */
$pathway_import = new sil_pathway_xhtml_Import();
register_importer('pathway-xhtml',
		__('SIL FLEX XHTML', 'sil_dictionary'),
		__('Import posts from an SIL FLEX XHTML file.', 'sil_dictionary'),
		array ($pathway_import, 'start'));

//} // class_exists( 'WP_Importer' )


//===================================================================================//

function pathway_xhtml_importer_init() {
	/*
	 * Load the translated strings for the plugin.
	 */
    load_plugin_textdomain('sil_dictionary', false, dirname(plugin_basename(__FILE__ )) .'/lang/');
}

//===================================================================================//

/*
 * Hook the importer's init into the WordPress init.
 */
add_action('init', 'pathway_xhtml_importer_init');
