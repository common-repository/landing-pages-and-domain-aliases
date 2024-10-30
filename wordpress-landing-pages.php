<?php
/**
 * @package wordpress landing pages
 */
/*
Plugin Name: Landing pages and Domain aliases for Wordpress
Plugin URI: https://wpdrift.no/landing-pages-and-domain-aliases/
Description: Let you use Wordpress for many domains and set different frontpage for each of domain. Does not require multisite feature. Great for landing pages and per-product per-page domain setup.
Version: 0.8
Author: Zondo Norge AS
Author URI: https://www.zondo.no
License: GPLv2 or later
*/

defined('ABSPATH') or die("No script kiddies please!");



class wordpress_landing_pages {
	private static $domains = array();
	private static $visiting_domain = "";

	private static $default_frontpage_id=0;
	private static $default_post_id=0;

	private static $plugin_options = array();

	private static $wp_option_name = "wordpress-landing-page-options";
	private static $wp_option_domain_list = "wordpress-landing-page-domains";


	function __construct() {
		self::$visiting_domain = $_SERVER["SERVER_NAME"];
	}

	static function init() {
		self::$visiting_domain = $_SERVER["SERVER_NAME"];
		self::$domains = get_option(self::$wp_option_domain_list);
		self::$plugin_options = get_option(self::$wp_option_name);


		$domain_found = false;
		$force_ssl = false;

		if (count(self::$domains)>0) {

			foreach(self::$domains as $dom=>$dom_opt) {
				if ($dom!="") {
					if (mb_eregi($dom, self::$visiting_domain) && !$domain_found) {
						
						# domain found, first check if we should redirect
						if ($dom_opt["show_on_front"]=="redirect" && $dom_opt["redirect_to"]!="") {
							header("Location: ".$dom_opt["redirect_to"]);
							exit();
						}


						self::set_wp_options($dom_opt["show_on_front"], $dom_opt["page_on_front"]);

						$domain_found=true;

						if ($dom_opt["use_ssl"]==1)
							$force_ssl=true;


						# override template if selected
						if ($dom_opt["override_template"]==1) {
							add_filter( 'template_include', function( $template ) {
							  $path = explode('/', $template );
							  $template_chosen = end( $path );

							  if ($template_chosen!="page.php")
							  	$path[(count($path)-1)]="page.php";

							  $template = implode("/",$path);

							  self::set_wp_options(self::$plugin_options["show_on_front"], self::$plugin_options["page_on_front"], self::$plugin_options["page_for_posts"]);
							  
							  return $template;
							});
						}
					}
				}
			}
		}


		if (!isset(self::$plugin_options["use_ssl"])) {
			self::$plugin_options["use_ssl"]=0;
		}


		if (self::$plugin_options["main_domain"]==self::$visiting_domain && !$domain_found) {

			self::set_wp_options(self::$plugin_options["show_on_front"], self::$plugin_options["page_on_front"], self::$plugin_options["page_for_posts"]);

			$domain_found=true;

			if (self::$plugin_options["use_ssl"]==1)
				$force_ssl=true;
		}


		if (!$domain_found) {
			if (self::$plugin_options["default_action"]=="accept_all") {
				// silently accept all domains pointing to us
				// for now just show main page

				self::set_wp_options(self::$plugin_options["show_on_front"], self::$plugin_options["page_on_front"], self::$plugin_options["page_for_posts"]);

				$domain_found=true;

				if (self::$plugin_options["use_ssl"]==1)
					$force_ssl=true;

			} else if (self::$plugin_options["default_action"]=="auto_add") {
				// accept all and add to configuration

				$dom_opt = array("show_on_front"=>self::$plugin_options["show_on_front"], "page_on_front"=>self::$plugin_options["page_on_front"]);

				if (self::$plugin_options["use_ssl"]==1)
					$dom_opt["use_ssl"]=1;

				$dom_opt["override_template"]=0;


				if (self::$visiting_domain!="") {
					self::$domains[self::$visiting_domain]=$dom_opt;
					update_option(self::$wp_option_domain_list, self::$domains);

					self::set_wp_options(self::$plugin_options["show_on_front"], self::$plugin_options["page_on_front"], self::$plugin_options["page_for_posts"]);

					$domain_found=true;

					if (self::$plugin_options["use_ssl"]==1)
						$force_ssl=true;
				}


			} else {
				// do nothing.
			}
		}



		if ($domain_found) {

			// found domain, let allow it to be served by Wordpress
			if ($force_ssl) {
				add_filter('content_url', [ "wordpress_landing_pages", 'set_correct_domain_ssl' ]);
				add_filter('option_siteurl', [ "wordpress_landing_pages", 'set_correct_domain_ssl' ]);
				add_filter('option_home', [ "wordpress_landing_pages", 'set_correct_domain_ssl' ]);
			} else {
				add_filter('content_url', [ "wordpress_landing_pages", 'set_correct_domain' ]);
				add_filter('option_siteurl', [ "wordpress_landing_pages", 'set_correct_domain' ]);
				add_filter('option_home', [ "wordpress_landing_pages", 'set_correct_domain' ]);
			}
		}
	}

	static function set_wp_options($show_on_front, $page_on_front, $page_for_posts=0) {


		if ($show_on_front=="page") {
			update_option('page_on_front', $page_on_front);
			if ($page_for_posts>0)
				update_option('page_for_posts', $page_for_posts);
			update_option('show_on_front', 'page');
		} else {
			//update_option('page_on_front', $page_on_front);
			update_option('show_on_front', 'posts');
		}


		return true;
	}

	static function admin_init() {
		$hook = is_multisite() ? 'network_' : '';
		add_action( "{$hook}admin_menu", array("wordpress_landing_pages", "add_admin_menu" ) );
	}

	static function add_action_links ( $links ) {
		 $mylinks = array(
		 '<a href="' . admin_url( 'options-general.php?page=wordpress_landing_pages' ) . '">Settings</a>',
		 );
		return array_merge( $links, $mylinks );
	}

	static function add_admin_menu() {
		add_options_page( 'Landing pages and Aliases', 'Landing pages and Aliases', 'manage_options', 'wordpress_landing_pages', array("wordpress_landing_pages", "admin_menu_options") );
	}


	static function admin_menu_options() {
		if ( !is_super_admin() )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}

		# check permissions
		if (current_user_can("install_plugins")) {

			if ( isset($_POST["subaction"]) && $_POST["subaction"]=="save_options") {
				check_admin_referer("save-landing-pages-options");

				$set_options = self::$plugin_options;



				if ($_POST["main_domain"]!="")
					$set_options["main_domain"]=sanitize_text_field($_POST["main_domain"]);
				else
					$set_options["main_domain"]=$_SERVER["SERVER_NAME"];


				if ($_POST["default_action"]=="accept_all" || $_POST["default_action"]=="auto_add" || $_POST["default_action"]=="none")
					$set_options["default_action"]=sanitize_text_field($_POST["default_action"]);
				else
					$set_options["default_action"]="accept_all";


				if ($_POST["show_on_front"]=="posts" || $_POST["show_on_front"]=="page")
					$set_options["show_on_front"]=sanitize_text_field($_POST["show_on_front"]);
				else
					$set_options["show_on_front"]=get_option("show_on_front");


				if (is_int($_POST["page_on_front"]) && $_POST["page_on_front"]>0)
					$set_options["page_on_front"]=sanitize_text_field($_POST["page_on_front"]);

				if (is_int($_POST["page_for_posts"]) && $_POST["page_for_posts"]>0)
					$set_options["page_for_posts"]=sanitize_text_field($_POST["page_for_posts"]);



				if ($_POST["use_ssl"]==1)
					$set_options["use_ssl"]=1;
				else
					$set_options["use_ssl"]=0;


				update_option(self::$wp_option_name, $set_options);
				self::$plugin_options = $set_options;


				#$set_domains = self::$domains;
				#update_option(self::$wp_option_domain_list, $set_domains);
				#self::$domains=$set_domains;

				print("
					<div class='updated'><p><strong>".__('Great, settings have been saved successfully!')."</strong></p></div>
				");
			} else if (isset($_POST["subaction"]) && $_POST["subaction"]=="save_domains") {
				
				check_admin_referer("save-domain-landing-pages-domains");

				if (count(self::$domains)>0) {
					foreach(self::$domains as $dom=>$dom_opt) {

						if ($dom=="") {
							unset(self::$domains[$dom]);
						} else {
							$dom_key = md5($dom);

							if (!is_array(self::$domains))
								self::$domains=array();
							if (!is_array(self::$domains[$dom]))
								self::$domains[$dom]=array();

							if (isset($_POST["show_on_front_".$dom_key])) {

								# sanitize input
								$t_show_on_front=sanitize_text_field($_POST["show_on_front_".$dom_key]);
								$t_use_ssl=(isset($_POST["use_ssl_".$dom_key])?sanitize_text_field($_POST["use_ssl_".$dom_key]):0);
								$t_redirect_to=sanitize_text_field($_POST["redirect_to_".$dom_key]);
								$t_page_on_front=sanitize_text_field($_POST["page_on_front_".$dom_key]);
								#$t_override_template=sanitize_text_field($_POST["override_template_".$dom_key]);


								
								if ($t_show_on_front=="redirect") {

									if ($t_redirect_to!="") {
										self::$domains[$dom]["show_on_front"]=$t_show_on_front;


										if (!mb_eregi("^http", $t_redirect_to))
											$t_redirect_to="http://".$t_redirect_to;


										if ($t_use_ssl==1) {
											self::$domains[$dom]["redirect_to"]=mb_eregi_replace("^http:", "https:", $t_redirect_to);
										} else {
											self::$domains[$dom]["redirect_to"]=mb_eregi_replace("^https:", "http:", $t_redirect_to);
										}
									}
								} else {
									
									if ($t_page_on_front>0) {
										self::$domains[$dom]["show_on_front"]=$t_show_on_front;
										self::$domains[$dom]["page_on_front"]=$t_page_on_front;
									}
								}


								self::$domains[$dom]["use_ssl"]=0;
								if (isset($_POST["use_ssl_".$dom_key])) {
									if ($_POST["use_ssl_".$dom_key]==1)
										self::$domains[$dom]["use_ssl"]=1;
								}

								
								self::$domains[$dom]["override_template"]=0;
								if (isset($_POST["override_template_".$dom_key])) {
									if ($_POST["override_template_".$dom_key]==1)
										self::$domains[$dom]["override_template"]=1;									
								}
							}


						}

					}

					update_option(self::$wp_option_domain_list, self::$domains);
				}

				print("
					<div class='updated'><p><strong>".__('Great, settings have been saved successfully!')."</strong></p></div>
				");

			} else if (isset($_POST["subaction"]) && $_POST["subaction"]=="add_domain") {
				
				check_admin_referer("add-domain-landing-pages");

				# sanitize input
				$t_new_domain=sanitize_text_field($_POST["new_domain"]);
				$t_new_show_on_front=sanitize_text_field($_POST["new_show_on_front"]);
				$t_new_page_on_front=(isset($_POST["new_page_on_front"])?sanitize_text_field($_POST["new_page_on_front"]):"");
				$t_new_use_ssl=(isset($_POST["new_use_ssl"])?sanitize_text_field($_POST["new_use_ssl"]):0);
				$t_new_redirect_to=(isset($_POST["new_redirect_to"])?sanitize_text_field($_POST["new_redirect_to"]):"");
				$t_new_override_template=(isset($_POST["override_template"])?sanitize_text_field($_POST["override_template"]):0);

				self::$domains[$t_new_domain]=array("show_on_front"=>$t_new_show_on_front, "page_on_front"=>$t_new_page_on_front);

				if ($t_new_use_ssl==1)
					self::$domains[$t_new_domain]["use_ssl"]=1;
				else
					self::$domains[$t_new_domain]["use_ssl"]=0;

				if ($t_new_override_template==1)
					self::$domains["override_template"]=1;
				else
					self::$domains["override_template"]=0;


				if ($t_new_show_on_front=="redirect") {

					if ($t_new_redirect_to!="") {

						# add domain
						self::$domains[$t_new_domain]=array("show_on_front"=>$t_new_show_on_front, "page_on_front"=>$t_new_page_on_front);
						if ($t_new_use_ssl==1)
							self::$domains[$t_new_domain]["use_ssl"]=1;
						else
							self::$domains[$t_new_domain]["use_ssl"]=0;


						if (!mb_eregi("^http", $t_new_redirect_to))
							$t_new_redirect_to="http://".$t_new_redirect_to;


						if ($t_new_use_ssl==1) {
							self::$domains[$t_new_domain]["redirect_to"]=mb_eregi_replace("^http:", "https:", $t_new_redirect_to);
						} else {
							self::$domains[$t_new_domain]["redirect_to"]=mb_eregi_replace("^https:", "http:", $t_new_redirect_to);
						}

						# save domain list
						update_option(self::$wp_option_domain_list, self::$domains);
					}
				} else if ($t_new_show_on_front=="posts" || $t_new_show_on_front=="page") {

					# add domain
					self::$domains[$t_new_domain]=array("show_on_front"=>$t_new_show_on_front, "page_on_front"=>$t_new_page_on_front);

					if ($t_new_use_ssl==1)
						self::$domains[$t_new_domain]["use_ssl"]=1;
					else
						self::$domains[$t_new_domain]["use_ssl"]=0;

					# save domain list
					update_option(self::$wp_option_domain_list, self::$domains);
				}



				print("
					<div class='updated'><p><strong>".__('Great, settings have been saved successfully!')."</strong></p></div>
				");

			} else if (isset($_GET["subaction"]) && $_GET["subaction"]=="delete_domain" && $_GET["domain_key"]!="") {
				
				$t_domain_key=sanitize_text_field($_GET["domain_key"]);
				
				check_admin_referer("landing-pages-delete_".$t_domain_key);
				
				
				if (count(self::$domains)>0) {


					

					if ($t_domain_key!="") {
						foreach(self::$domains as $dom=>$dom_opt) {
							$dom_key = md5($dom);
							if($dom_key==$t_domain_key) {
								unset(self::$domains[$dom]);
							}
						}

						update_option(self::$wp_option_domain_list, self::$domains);

						print("
							<div class='updated'><p><strong>".__('Great, domain list has been updated!')."</strong></p></div>
						");
					}


				}
			}

			# page_on_front
			# page_for_posts
			$page_ids=get_all_page_ids();
			$page_on_front_txt = "";
			$page_for_posts_txt = "";
			$page_on_front_array = array();

			foreach($page_ids as $page)
			{
				#echo '<br />'.$page.":".get_the_title($page);
				# $page_options_txt="<

				$this_title = get_the_title($page);
				$page_on_front_array[]=array("page_id"=>$page, "page_title"=>$this_title);

				$page_on_front_txt .= "<option value='".$page."'" . ((self::$plugin_options["page_on_front"]==$page)?" selected":"") . ">".$this_title."</option>";
				$page_for_posts_txt .= "<option value='".$page."'" . ((self::$plugin_options["page_for_posts"]==$page)?" selected":"") . ">".$this_title."</option>";

			}


			print("
			<div class='wrap' style='float:left;'>
				<h2>".__("Configure landing pages and additional domains that are allowed to point to this website")."</h2>

				<form action='' method='post' class='form-horizontal'>
					<input type='hidden' name='subaction' value='save_options'>
			");
			
			wp_nonce_field( 'save-landing-pages-options' );
			
			print("

					<table class='form-table'>
					<tbody>

						<tr>
							<th scope='row'>
								<label class='col-sm-6 control-label'>".__("Main website domain")."</label>
							</th>
							<td>
								<input type='text' name='main_domain' value='".self::$plugin_options["main_domain"]."' class='form-control'>

								<label><input type='checkbox' name='use_ssl' value='1'" . ((self::$plugin_options["use_ssl"]==1)?" checked":"")."> - force SSL</label>
							</td>
						</tr>

						<tr>
							<th scope='row'>
								<label class='col-sm-6 control-label'>".__("Default action")."</label>
							</th>
							<td>
								<select name='default_action'>
									<option value='accept_all'" . ((self::$plugin_options["default_action"]=="accept_all")?" selected":"").">".__("Silently accept all domains, that point to this website")."</option>
									<option value='auto_add'" . ((self::$plugin_options["default_action"]=="auto_add")?" selected":"").">".__("Automatically add missing domain to plugin configuration")."</option>
									<option value='none'" . ((self::$plugin_options["default_action"]=="none")?" selected":"").">".__("Ignore unconfigured domains and redirect to main domain")."</option>
								</select>
							</td>
						</tr>


						<tr>
							<th scope='row'>
								<label class='col-sm-6 control-label'>".__("Default frontpage")."</label>
							</th>
							<td>
								<select name='show_on_front'>
									<option value='posts'" . ((self::$plugin_options["show_on_front"]=="posts")?" selected":"") . ">" . __("Show latest posts") . "</option>
									<option value='page'" . ((self::$plugin_options["show_on_front"]=="page")?" selected":"") . ">" . __("Show static page") . "</option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope='row'>
								<label class='col-sm-6 control-label'>".__("Show as frontpage") . "</label>
							</th>
							<td>
								<select name='page_on_front'" . ((self::$plugin_options["show_on_front"]=="posts")?" disabled":"") . ">" . $page_on_front_txt . "</select>
							</td>
						</tr>

						<tr>
							<th scope='row'>
								<label class='col-sm-6 control-label'>".__("Page for posts")."</label>
							</th>
							<td>
								<select name='page_for_posts'" . ((self::$plugin_options["show_on_front"]=="posts")?" disabled":"") . ">" . $page_for_posts_txt . "</select>
							</td>
						</tr>

						<tr>
							<th scope='row'>
								<label class='col-sm-6 control-label'>&nbsp;</label>
							</th>
							<td>
								<input class='button button-primary' type='submit' value='".__("Save default and global settings!")."'>
							</td>
						</tr>

						</tbody>
				</table>



				</form>
				");


				$page_on_front_txt="";
				foreach($page_on_front_array as $pp) {

					if (isset($dom_opt["page_on_front"])) {
						// page_id, page_title
						$page_on_front_txt .= "<option value='".$pp["page_id"]."'" . (($pp["page_id"]==$dom_opt["page_on_front"])?" selected":"") . ">" . $pp["page_title"] . "</option>";
					}
				}

				# <pre>".print_r(self::$domains, true)."</pre>

				print("

				<hr>

				<h2>Add new domain to configuration</h2>

				<form action='' method='post' class='form-vertical'>
					<input type='hidden' name='subaction' value='add_domain'>
				");
				
				wp_nonce_field( 'add-domain-landing-pages' );
				
				print("
					<table>
					<thead>
						<tr>
							<th>" . __("Domain name") . "</th>
							<th>" . __("Enable SSL")  ."</th>
							<th>" . __("Frontpage") . "</th>
							<th>" . __("Home page") . "</th>
							<th>" . __("Override template") . "</th>
							<th>&nbsp;</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>
								<input type='text' class='form-control' name='new_domain'>
							</td>
							<td align='center'>
								<input type='checkbox' class='form-control' name='new_use_ssl' value='1'>
							</td>
							<td>
								<select name='new_show_on_front' onChange='change_new(this);'><option value='posts'>" . __("Show latest posts") . "</option><option value='page'>" . __("Show static page") . "</option><option value='redirect'>" . __("Redirect to other domain") . "</option></select>
							</td>
							<td>
								<select name='new_page_on_front' class='page_on_front_select'>".$page_on_front_txt."</select>
								<input type='text' name='new_redirect_to' class='form-control redirect_to_input' style='display:none; width:300px;'>
							</td>
							<td align='center'>
								<input type='checkbox' class='form-control' name='override_template' value='1'>
							</td>
							<td>
								<button type='submit' class='button button-primary'>" . __("Add domain to configuration") . "</button>
							</td>
						</tr>
					</tbody>
					</table>

				</form>

				<hr>

				<h2>Domain alias and landing page configuration for domains</h2>



				<form action='' method='post' class='form-horizontal'>
					<input type='hidden' name='subaction' value='save_domains'>

				");

				
				wp_nonce_field( 'save-domain-landing-pages-domains' );
				
				print("					
					<table>
						<thead>
							<tr>
								<th>".__("Domain name")."</th>
								<th>".__("Force SSL")."</th>
								<th>".__("Frontpage")."</th>
								<th>".__("Home page")."</th>
								<th>".__("Override template")."</th>
								<th>&nbsp;</th>
							</tr>
						</thead>
						<tbody>
				");



					foreach(self::$domains as $dom=>$dom_opt) {
						$dom_key = md5($dom);

						if (!is_array($dom_opt))
							$dom_opt=array(
								"show_on_front"=>"page",
								"page_on_front"=>0,
								"redirect_to"=>"",
								"override_template"=>0,
								"use_ssl"=>0
							);

						$show_on_front_txt = "
							<option value='posts'" . (($dom_opt["show_on_front"]=="posts")?" selected":"") . ">" . __("Show latest posts") . "</option>
							<option value='page'" . (($dom_opt["show_on_front"]=="page")?" selected":"") . ">" . __("Show static page") . "</option>
							<option value='redirect'" . (($dom_opt["show_on_front"]=="redirect")?" selected":"") . ">" . __("Redirect to other domain") . "</option>
						";

						$page_on_front_txt="";
						foreach($page_on_front_array as $pp) {
							// page_id, page_title
							$page_on_front_txt .= "<option value='".$pp["page_id"]."'" . (($pp["page_id"]==$dom_opt["page_on_front"])?" selected":"") . ">" . $pp["page_title"] . "</option>";
						}


						if (!isset($dom_opt["redirect_to"])) $dom_opt["redirect_to"]="";
						if (!isset($dom_opt["override_template"])) $dom_opt["override_template"]=0;

						print("
							<tr>
								<th scope='row'>
									<label class='col-sm-6 control-label'>".esc_url($dom)."</label>
								</th>
								<td align='center'>
									<input type='checkbox' name='use_ssl_".$dom_key."' value='1'" . (($dom_opt["use_ssl"]==1)?" checked":"") . ">
								</td>
								<td>
									<select name='show_on_front_".$dom_key."' onChange='change_new(this);'>".$show_on_front_txt."</select>
								</td>
								<td>
									<select name='page_on_front_".$dom_key."' class='page_on_front_select'" . (($dom_opt["show_on_front"]=="redirect")?" style='display:none;'":"") . ">".$page_on_front_txt."</select>
									<input type='text' name='redirect_to_".$dom_key."' class='form-control redirect_to_input' value='".esc_url($dom_opt["redirect_to"])."' style='width:100%;" . (($dom_opt["show_on_front"]!="redirect")?" display:none":"") . "'>
								</td>

								<td align='center'> 
									<input type='checkbox' name='override_template_".$dom_key."' value='1'" . (($dom_opt["override_template"]==1)?" checked":"") . ">
								</td>

								<td><button type='button' class='button button-small' style='color:darkred;' onClick='window.location.href=\"?page=wordpress_landing_pages&subaction=delete_domain&domain_key=" . $dom_key."&_wpnonce=" . wp_create_nonce( 'landing-pages-delete_'.$dom_key ) . "\";'>".__("Delete")."</button></td>
							</tr>
						");

					}



				print("
						<tr>
							<td colspan='4'>
								<button type='submit' class='button button-primary'>" . __("Save domain configuration") . "</button>
							</td>
						</tr>

						</tbody>
					</table>

				</form>


			</div>

			<div class='wrap' style='float: left;background: #ff9800;box-shadow: 0px 0px 5px 0px black;border-radius: 15px;padding: 0 1em 1em 1em;text-align: center;'>
				<h2>
					Like this plugin?
				</h2>
				<p>By donating you help keep plugin maintained, and further developed.</p>
				<form action='https://www.paypal.com/donate' method='post' target='_top'>
					<input type='hidden' name='hosted_button_id' value='DJRDCW2ACFB5Q' />
					<input type='image' src='https://www.paypalobjects.com/en_US/i/btn/btn_donate_LG.gif' border='0' name='submit' title='PayPal - The safer, easier way to pay online!' alt='Donate with PayPal button' />
					<img alt='' border='0' src='https://www.paypal.com/en_NO/i/scr/pixel.gif' width='1' height='1' />
				</form>
			</div>


			<script language='javascript'>
				function change_new(e) {
					if (jQuery(e).val()=='redirect') {
						jQuery(e).parent().parent().find('.redirect_to_input').css('display', '');
						jQuery(e).parent().parent().find('.page_on_front_select').css('display', 'none');
					} else {
						jQuery(e).parent().parent().find('.page_on_front_select').css('display', '');
						jQuery(e).parent().parent().find('.redirect_to_input').css('display', 'none');
					}
				}


			</script>

			");

		} # end current_user_can()
	}



	static function plugin_activation() {

		$current_options = get_option(self::$wp_option_name);
		if ($current_options===false)
			$current_options=array();

		if (count($current_options)==0 || !is_array($current_options)) {
			# save default plugin settings.
			$odef = array(
				"main_domain"=>$_SERVER["SERVER_NAME"],
				"default_action"=>"accept_all",
				"show_on_front"=>get_option("show_on_front"),
				"page_on_front"=>get_option("page_on_front"),
				"page_for_posts"=>get_option("page_for_posts")
			);

			update_site_option(self::$wp_option_name, $odef);
			self::$plugin_options=$odef;
		}

		$current_domains = get_option(self::$wp_option_domain_list);
		if ($current_domains===false)
			$current_domains=array();

		if (count($current_domains)<1 || !is_array($current_domains)) {
			$domain_list = array();
			update_site_option(self::$wp_option_domain_list, $domain_list);
			self::$domains = $domain_list;
		}
	}


	static function plugin_deactivation() {}





	static function set_correct_domain($url="")
    {

		# return($url);

		$orig_url=$url;

        if (!preg_match('/\/wp-admin\/?/', $url)) {
            $domain = self::getDomainFromUrl($url);
            $url = mb_eregi_replace($domain, self::$visiting_domain, $url);
        }

		#if (ISSENTANTON!=1) {
		#	mail("anton@nordichosting.com", "replaceDomain", $orig_url.":".$url.":".$domain);
		#	define("ISSENTANTON", "1");
		#}

        return $url;

    }
	static function set_correct_domain_ssl($url="")
    {

		# return($url);

		$orig_url=$url;

        if (!preg_match('/\/wp-admin\/?/', $url)) {
            $domain = self::getDomainFromUrl($url);
            $url = mb_eregi_replace($domain, self::$visiting_domain, $url);
			$url = mb_eregi_replace("^http:", "https:", $url);
        }

		#if (ISSENTANTON!=1) {
		#	mail("anton@nordichosting.com", "replaceDomain", $orig_url.":".$url.":".$domain);
		#	define("ISSENTANTON", "1");
		#}

        return $url;

    }
	static function getDomainFromUrl($url)
    {
        $parts = parse_url($url);
        $domain = $parts['host'];
        if (!empty($parts['port'])) {
            $domain .= ':' . $parts['port'];
        }
        return $domain;
    }
}



#function load_wordpress_landing_pages() {
#	$lp = new wordpress_landing_pages();
#}

add_action( 'init', array('wordpress_landing_pages', 'init') );

if ( is_admin() ) {
	register_activation_hook( __FILE__, array( 'wordpress_landing_pages', 'plugin_activation' ) );
	register_deactivation_hook( __FILE__, array( 'wordpress_landing_pages', 'plugin_deactivation' ) );

	add_action( 'init', array( 'wordpress_landing_pages', 'admin_init' ) );
	add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array('wordpress_landing_pages', 'add_action_links') );
}

?>