<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * repository_soundcloud class
 *
 * @since 2.0
 * @package    repository
 * @subpackage soundcloud
 * @copyright  2011 Troy Williams
 * @author     Troy Williams <troyw@waikato.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot.'/repository/soundcloud/soundcloudapi.php');

class repository_soundcloud extends repository {
    
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array()) {
        global $CFG;
        
        // TODO check if empty?
        $this->clientid = get_config('soundcloud', 'clientid');
        $this->clientsecret  = get_config('soundcloud', 'clientsecret');
        $this->redirecturi = $CFG->wwwroot.'/repository/repository_callback.php?repo_id='.$repositoryid;
        
        $this->accesstoken = get_user_preferences('soundcloud_accesstoken', '');
        // load up te php soundcloud wrapper
        $this->soundcloudapi = new Services_Soundcloud($this->clientid, $this->clientsecret, $this->redirecturi);
        
        if ($this->accesstoken) {
            $this->soundcloudapi->setAccessToken($this->accesstoken);
        }
        
        parent::__construct($repositoryid, $context, $options);
    }

    public function check_login() {
        return !empty($this->accesstoken);
    }
    
    public function callback() {
        global $CFG;
        
        $code = optional_param('code', false, PARAM_RAW);
        $error = optional_param('error', false, PARAM_RAW);
        $errordescription = optional_param('error_description', false, PARAM_RAW);
        
        // throw exception, id, secret or redirect probably wrong
        if ($error) {
            throw new moodle_exception($errordescription, 'soundcloud');
        }
        
        if ($code) {
           $response = $this->soundcloudapi->accessToken($code); // response returned as array
           set_user_preference('soundcloud_accesstoken', $response['access_token']); 
        }
        
    }

    public function global_search() {
        return false;
    }
    
    /**
     *
     * @param type $trackid
     * @param type $filename
     * @return type 
     */
    public function get_file($trackid, $filename) {
        
        $track = $this->soundcloudapi->get('me/tracks/'.$trackid);
        $track = json_decode($track);
        
        // Check downloads remaining. TODO - how does this function under Pro accounts 
        if ($track->downloads_remaining === 0) {
            throw new moodle_exception('download count for this track has exceeded', 'soundcloud');
        }    
        $path = $this->prepare_file($filename);
        try {
            
            $data = $this->soundcloudapi->download($track->id);
            file_put_contents($path, $data);
            
        } catch (Exception $e) {
            
            throw new moodle_exception($e->getMessage(), 'soundcloud');
        }
        
        return array('path'=>$path, 'url'=>$track->uri);
    }
    
    /**
     *
     * @param type $trackid
     * @return string 
     */
    public function get_link($trackid) {
        
        $track = $this->soundcloudapi->get('me/tracks/'.$trackid);
        $track = json_decode($track);
       
        $baseurl = $track->user->permalink_url . '/tracks/?' . $track->id; // link to track on Soundcloud, id will allow filter to work
        
        $url = $baseurl.'#'.$track->title; // add #title so Moodle will add link name
        if ($track->sharing === 'private') {
            $url = $baseurl . '&secret_token='. $track->secret_token . '#' . $track->title; // token will allow filter to work for private track
        }
       
        return $url;
    }
    
    /**
     *
     * @global type $CFG
     * @global type $OUTPUT
     * @param type $path
     * @param type $page
     * @return int 
     */
    public function get_listing($path='', $page = '') {
        global $CFG, $OUTPUT;
        
        $ret  = array();
        // log out link will be displayed
        $ret['nologin'] = false;
        $ret['nosearch'] = true;
        $ret['list'] = array();
        $tracks = $this->soundcloudapi->get('me/tracks');
        $tracks = json_decode($tracks);
        if ($tracks) {
            foreach($tracks as $track) {
                $filename = $track->title. '.' . $track->original_format; // download will be original file, so use original extension 
                if (empty($track->artwork_url)){
                    $thumbnail = $OUTPUT->pix_url(file_extension_icon('.'.$track->original_format, 32));
                } else {
                    $thumbnail = $track->artwork_url;
                }
                $list[] = array(
                                'title'=>(string)$filename,
                                'thumbnail'=>(string)$thumbnail,
                                'thumbnail_width'=>64,
                                'thumbnail_height'=>64,
                                'size'=>'',
                                'date'=>(string)$track->created_at,
                                'source'=>$track->id
                                );
            }
            $ret['list'] = $list;
        }
        
        return $ret;
    }
    
    /**
     *
     * @param string $ajax
     * @return array
     */
    public function print_login($ajax = true){
        global $CFG;
        
        if ($ajax) {
            $ret = array();
            $popup_btn = new stdClass();
            $popup_btn->type = 'popup';
            $popup_btn->url = $this->soundcloudapi->getAuthorizeUrl(array('scope'=>'non-expiring'));
            $ret['login'] = array($popup_btn);
            return $ret;
        }
    }
    
    /**
     *
     * @return mixed
     */
    public function logout() {
        
        set_user_preference('soundcloud_accesstoken', '');
        return parent::logout();
    }
    
    /**
     * Add Plugin settings input to Moodle form
     * @param object $mform
     */
    public function type_config_form($mform) {
        global $CFG;
           
        $clientid = get_config('soundcloud', 'clientid');
        $clientsecret = get_config('soundcloud', 'clientsecret');
        
        if (empty($clientid)) {
            $clientid = '';
        }
        if (empty($clientsecret)) {
            $clientsecret = '';
        }
        $strrequired = get_string('required');
        $mform->addElement('text', 'clientid', get_string('clientid', 'repository_soundcloud'), array('value'=>$clientid, 'size'=>'40'));
        $mform->addElement('text', 'clientsecret', get_string('clientsecret', 'repository_soundcloud'), array('value'=>$clientsecret, 'size'=>'40'));
        // get Soundcloud instances
        $params = array();
        $params['onlyvisible'] = false;
        $params['type'] = 'soundcloud';
        $instances = repository::get_instances($params);
        if (empty($instances)) {
            $redirecturi = get_string('redirecturiwarning', 'repository_soundcloud');
            $mform->addElement('static', null, '',  $redirect_uri);
        } else {
            $instance = array_shift($instances);
            $redirecturi = $CFG->wwwroot.'/repository/repository_callback.php?repo_id='.$instance->id;
            $mform->addElement('static', 'redirect_uri', '', get_string('redirecturitext', 'repository_soundcloud', $redirecturi));
        }
        $mform->addRule('clientid', $strrequired, 'required', null, 'client');
        $mform->addRule('clientsecret', $strrequired, 'required', null, 'client'); 
    }
    
    /**
     * Need for Soundcloud configuration variables.
     * @return type 
     */
    public static function get_type_option_names() {
        return array('clientid', 'clientsecret', 'pluginname');
    }
    
    public function supported_filetypes() {
        return array('web_audio');
    }

}
