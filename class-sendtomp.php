<?php

GFForms::include_addon_framework();

class GFSimpleAddOn extends GFAddOn {
 
 protected $_version = GF_SENDTOMP_VERSION;
 protected $_min_gravityforms_version = '1.9';
 protected $_slug = 'sendtomp';
 protected $_path = 'sendtomp/sendtomp.php';
 protected $_full_path = __FILE__;
 protected $_title = 'Gravity Forms Send to MP Addon';
 protected $_short_title = 'Send to MP Add-On';

 public function pre_init() {
     parent::pre_init();
     // add tasks or filters here that you want to perform during the class constructor - before WordPress has been completely initialized
 }

 public function init() {
     parent::init();
     // add tasks or filters here that you want to perform both in the backend and frontend and for ajax requests
 }

 public function init_admin() {
     parent::init_admin();
     // add tasks or filters here that you want to perform only in admin
 }

 public function init_frontend() {
     parent::init_frontend();
     // add tasks or filters here that you want to perform only in the front end
 }

 public function init_ajax() {
     parent::init_ajax();
     // add tasks or filters here that you want to perform only during ajax requests
 }
}