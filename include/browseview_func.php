<?php
function displayAlphabet($alphas, $languagecode)
{
?>
	<style type="text/css">

	.lpTitleLetterCell {min-width:31px; height: 23x; padding-top: 3px; padding-bottom: 2px; text-bottom; text-align:center;background-color: #EEEEEE;cursor:pointer;cursor:hand;border:1px solid silver; float:left; position: relative;}
	</style>	
<?php 
	$display = "<br>"; 
	$display .= "<div style=\"min-width: 270px; width: 100%;\">";
	foreach($alphas as $letter)
	{
    	$display .= "<div class=\"lpTitleLetterCell\"><span class=lpTitleLetter><a href=\"?letter=" . $letter . "&key=" . $languagecode . "\">" . $letter . "</a></span></div>";
	}
	$display .= "</div>";
	$display .=  "<div style=clear:both></div>";
	
	return $display;
	
}

function displayPagenumbers($totalEntries, $entriesPerPage, $languagecode)
{
	$totalPages = $totalEntries / $entriesPerPage;
	for($page = 1; $page <= $totalPages; $page++)
	{
		if($_GET['pagenr'] == $page || ($page == 1 && !isset($_GET['pagenr'])))
		{
			$display .= $page . " ";
		}
		else
		{
			$display .= "<a href=\"?letter=" . $chosenLetter . "&key=" . $languagecode . "&pagenr=" . $page . "&totalEntries=" . $totalEntries . "\">" . $page . "</a> ";
		}		 
	}		
	return $display;
}

function englishalphabet_func( $atts ) {
	
	$languagecode = "en";
	
	if(isset($_GET['letter']))
	{
		$chosenLetter = $_GET['letter']; 
	}
	else {
		$chosenLetter = "a"; 
	}
	
	?>
	<style type="text/css">
	#searchresult { 
		width:50%; 
		min-width: 270px;
		text-align:left; 
	}
	#englishcol { 
		float:left; 
		margin: 1px; 
		padding-left: 2px;
		width:60%; 
		text-align:left; 
	}
	#vernacularcol {
		text-align:left;
	}
	.odd { background: #CCCCCC; }; 
	.even { background: #FFF; }; 		
	</style>	
	<?php 		
	$alphas = range('a', 'z');
	$display = displayAlphabet($alphas, $languagecode);
	
	$page = $_GET['pagenr'];
	if(!isset($_GET['pagenr']))
	{
		$page = 1;
	}
	$arrAlphabet = getEnglishAlphabet($chosenLetter, $page);
	
	$display .=  "<div align=center>";
	$display .= "<h1>" . $chosenLetter . "</h1><br>";

	$background = "even";
	$count = 0;
	foreach($arrAlphabet as $alphabet)
	{
		$display .=  "<div id=searchresult class=" . $background . ">";	
			$display .=  "<div id=englishcol>";

			if($alphabet->English != $englishWord) 
			{
				$display .=  $alphabet->English; 
			}
			$englishWord = $alphabet->English;
			 $display .=  "</div>";
			$display .=  "<div id=vernacularcol><a href=\"/?s=" . trim($alphabet->Vernacular)  . "&search=Search&tax=-1&partialsearch=1\">" . $alphabet->Vernacular . "</a></div>";
		$display .=  "</div>";
		$display .=  "<div style=clear:both></div>";
		
		if($background == "even")
		{
			$background = "odd";
		}
		else 
		{
			$background = "even";
		}
		
    	$count++;
    	if($count == 50)
    	{
    		break;
    	}
	}
	
	if(!isset($_GET['totalEntries']))
	{
		$totalEntries = count($arrAlphabet);
	}
	else
	{
		$totalEntries = $_GET['totalEntries'];
	}

	$display .= displayPagenumbers($totalEntries, 50, $languagecode);

	$display .=  "</div><br>";
	
 return $display;
}

add_shortcode( 'englishalphabet', 'englishalphabet_func' );
 
function getEnglishAlphabet($letter, $page)
{
	global $wpdb;
	
	$sql = "SELECT a.search_strings AS English, b.search_strings AS Vernacular " .
	" FROM " . SEARCHTABLE . " a " .
	" INNER JOIN " . SEARCHTABLE. " b ON a.post_id = b.post_id AND a.subid = b.subid " .
	" AND a.language_code =  'en' " .
	" AND a.relevance >=95 " .
	" AND a.search_strings LIKE  '" . $letter . "%' " .
	" GROUP BY a.post_id " .
	" ORDER BY a.search_strings ";
	if($page > 1)
	{
		$startFrom = $page * 50;
		$sql .= " LIMIT " . $startFrom .", 50";
	}
	$arrAlphabet = $wpdb->get_results($sql);
	
	return $arrAlphabet;
}

function vernacularalphabet_func( $atts ) {
	
	$languagecode = "tlj";
	
	if(isset($_GET['letter']))
	{
		$chosenLetter = $_GET['letter']; 
	}
	else {
		$chosenLetter = "a"; 
	}
		
	$alphas = explode(",",  get_option('vernacular_alphabet'));
	$display = displayAlphabet($alphas, $languagecode);
	
	$display .= "<div align=center><h1>" . $chosenLetter . "</h1></div><br>";

    $display .= "<div id=searchresults>";
    
	$arrPosts = query_posts("s=a&letter=" . $chosenLetter . "&posts_per_page=25&paged=" . $_GET['pagenr']);

	foreach($arrPosts as $mypost)
	{
		$display .= "<div>" . $mypost->post_content . "</div>";
	}
	
	$display .= "</div>";
	
	if(!isset($_GET['totalEntries']))
	{
		global $wp_query;
		$totalEntries = $wp_query->found_posts;
	}
	else
	{
		$totalEntries = $_GET['totalEntries'];
	}
		
	$display .= "<div align=center><br>";
	$display .= displayPagenumbers($totalEntries, 25, $languagecode);
	$display .= "</div><br>";
	
 	wp_reset_query();
	return $display;
}

add_shortcode( 'vernacularalphabet', 'vernacularalphabet_func' );
?>