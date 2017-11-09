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
 * The mod_hvp view assets convenience class for viewing and embedding H5Ps
 *
 * @package    mod_hvp
 * @copyright  2017 Joubel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_hvp;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles finding and attaching assets for view
 * @package mod_hvp
 */
class view_assets {

    private $cm;
    private $course;
    private $core;
    private $content;
    private $jsrequires;
    private $cssrequires;

    protected $settings;
    protected $embedtype;
    protected $files;

    public function __construct($cm, $course, $forceembedtype = null) {
        global $CFG;

        $this->cm          = $cm;
        $this->course      = $course;
        $this->core        = framework::instance();
        $this->content     = $this->core->loadContent($cm->instance);
        $this->settings    = hvp_get_core_assets();
        $this->jsrequires  = [];
        $this->cssrequires = [];

        $context        = \context_module::instance($this->cm->id);
        $displayoptions = $this->core->getDisplayOptionsForView($this->content['disable'], $context->instanceid);

        // Add JavaScript settings for this content.
        $cid                                  = 'cid-' . $this->content['id'];
        $this->settings['contents'][ $cid ]   = array(
            'library'         => \H5PCore::libraryToString($this->content['library']),
            'jsonContent'     => $this->getfilteredparameters(),
            'fullScreen'      => $this->content['library']['fullscreen'],
            'exportUrl'       => $this->getexportsettings($displayoptions[ \H5PCore::DISPLAY_OPTION_DOWNLOAD ]),
            'embedCode'       => $this->getembedcode($displayoptions[ \H5PCore::DISPLAY_OPTION_EMBED ]),
            'resizeCode'      => $this->getresizecode($displayoptions[ \H5PCore::DISPLAY_OPTION_EMBED ]),
            'title'           => $this->content['title'],
            'displayOptions'  => $displayoptions,
            'url'             => "{$CFG->httpswwwroot}/mod/hvp/view.php?id={$this->cm->id}",
            'contentUrl'      => "{$CFG->httpswwwroot}/pluginfile.php/{$context->id}/mod_hvp/content/{$this->content['id']}",
            'contentUserData' => array(
                0 => content_user_data::load_pre_loaded_user_data($this->content['id'])
            )
        );
        $this->settings['ajax']['xAPIResult'] = $this->getxapiresultsurl()->out(false);

        $this->embedtype = isset($forceembedtype) ? $forceembedtype : \H5PCore::determineEmbedType(
            $this->content['embedType'], $this->content['library']['embedTypes']
        );

        $this->files = $this->getdependencyfiles();
        $this->generateassets();
    }

    /**
     * xAPI results ajax url for settings
     *
     * @return \moodle_url
     */
    private function getxapiresultsurl() {
        return new \moodle_url('/mod/hvp/ajax.php',
            array(
                'token'  => \H5PCore::createToken('xapiresult'),
                'action' => 'xapiresult'
            )
        );
    }

    /**
     * Filtered and potentially altered parameters
     *
     * @return Object|string
     */
    private function getfilteredparameters() {
        global $PAGE;

        $safeparameters = $this->core->filterParameters($this->content);
        $decodedparams  = json_decode($safeparameters);
        $hvpoutput      = $PAGE->get_renderer('mod_hvp');
        $hvpoutput->hvp_alter_filtered_parameters(
            $decodedparams,
            $this->content['library']['name'],
            $this->content['library']['majorVersion'],
            $this->content['library']['minorVersion']
        );
        $safeparameters = json_encode($decodedparams);

        return $safeparameters;
    }

    /**
     * Export path for settings
     *
     * @param $downloadenabled
     *
     * @return string
     */
    private function getexportsettings($downloadenabled) {
        global $CFG;

        if ( ! $downloadenabled || (isset($CFG->mod_hvp_export) && $CFG->mod_hvp_export === false)) {
            return '';
        }

	    $modulecontext = \context_module::instance($this->cm->id);
	    $slug          = $this->content['slug'] ? $this->content['slug'] . '-' : '';
	    $url           = \moodle_url::make_pluginfile_url($modulecontext->id,
		    'mod_hvp',
		    'exports',
		    '',
		    '',
		    "{$slug}{$this->content['id']}.h5p"
	    );

	    return $url->out();
    }

    /**
     * Embed code for settings
     *
     * @param $embedenabled
     *
     * @return string
     */
    private function getembedcode($embedenabled) {
        global $CFG;

        if ( ! $embedenabled) {
            return '';
        }

        $embedurl = new \moodle_url("{$CFG->httpswwwroot}/mod/hvp/embed.php?id={$this->cm->id}");

        return "<iframe src='{$embedurl->out()}' width=':w' height=':h' frameborder='0' " .
               "allowfullscreen='allowfullscreen'></iframe>";
    }

    /**
     * Resizing script for settings
     *
     * @param $embedenabled
     *
     * @return string
     */
    private function getresizecode($embedenabled) {
        global $CFG;

        if ( ! $embedenabled) {
            return '';
        }

        $resizeurl = new \moodle_url($CFG->httpswwwroot . '/mod/hvp/library/js/h5p-resizer.js');

        return "<script src='{$resizeurl->out()}' charset='UTF-8'></script>";
    }

    /**
     * Finds library dependencies of view
     *
     * @return array Files that the view has dependencies to
     */
    private function getdependencyfiles() {
        global $PAGE;

        $preloadeddeps = $this->core->loadContentDependencies($this->content['id'], 'preloaded');
        $files         = $this->core->getDependenciesFiles($preloadeddeps);

        // Add additional asset files if required.
        $hvpoutput = $PAGE->get_renderer('mod_hvp');
        $hvpoutput->hvp_alter_scripts($files['scripts'], $preloadeddeps, $this->embedtype);
        $hvpoutput->hvp_alter_styles($files['styles'], $preloadeddeps, $this->embedtype);

        return $files;
    }

    /**
     * Generates assets depending on embed type
     */
    private function generateassets() {
        global $CFG;

        if ($this->embedtype === 'div') {
            $context = \context_system::instance();
            $hvppath = "/pluginfile.php/{$context->id}/mod_hvp";

            // Schedule JavaScripts for loading through Moodle.
            foreach ($this->files['scripts'] as $script) {
                $url = $script->path . $script->version;

                // Add URL prefix if not external.
                $isexternal = strpos($script->path, '://');
                if ($isexternal === false) {
                    $url = $hvppath . $url;
                }
                $this->settings['loadedJs'][] = $url;
                $this->jsrequires[]           = new \moodle_url($isexternal ? $url : $CFG->httpswwwroot . $url);
            }

            // Schedule stylesheets for loading through Moodle.
            foreach ($this->files['styles'] as $style) {
                $url = $style->path . $style->version;

                // Add URL prefix if not external.
                $isexternal = strpos($style->path, '://');
                if ($isexternal === false) {
                    $url = $hvppath . $url;
                }
                $this->settings['loadedCss'][] = $url;
                $this->cssrequires[]           = new \moodle_url($isexternal ? $url : $CFG->httpswwwroot . $url);
            }
        } else {
            // JavaScripts and stylesheets will be loaded through h5p.js.
            $cid                                           = 'cid-' . $this->content['id'];
            $this->settings['contents'][ $cid ]['scripts'] = $this->core->getAssetsUrls($this->files['scripts']);
            $this->settings['contents'][ $cid ]['styles']  = $this->core->getAssetsUrls($this->files['styles']);
        }
    }

    public function getcontent() {
        return $this->content;
    }

    /**
     * Logs viewed to all handlers
     */
    public function logviewed() {
        $this->logh5pviewedevent();
        $this->logcompletioncriteriaviewed();
        $this->triggermoduleviewedevent();
    }

    /**
     * Logs content viewed to H5P core
     */
    public function logh5pviewedevent() {
        new event(
            'content', null,
            $this->content['id'], $this->content['title'],
            $this->content['library']['name'],
            $this->content['library']['majorVersion'] . '.' . $this->content['library']['minorVersion']
        );
    }

    /**
     * Logs activity viewed to completion criterias
     */
    public function logcompletioncriteriaviewed() {
        $completion = new \completion_info($this->course);
        $completion->set_module_viewed($this->cm);
    }

    /**
     * Allows observers to act on viewed event
     */
    public function triggermoduleviewedevent() {
        $event = event\course_module_viewed::create(array(
            'objectid' => $this->cm->instance,
            'context'  => \context_module::instance($this->cm->id)
        ));
        $event->add_record_snapshot('course_modules', $this->cm);
        $event->trigger();
    }


    /**
     * Adds js assets to current page
     */
    public function addassetstopage() {
        global $PAGE, $CFG;

        foreach ($this->jsrequires as $script) {
            $PAGE->requires->js($script, true);
        }

        foreach ($this->cssrequires as $css) {
            $PAGE->requires->css($css);
        }

        // Print JavaScript settings to page.
        $PAGE->requires->data_for_js('H5PIntegration', $this->settings, true);

        // Add xAPI collector script.
        $PAGE->requires->js(new \moodle_url($CFG->httpswwwroot . '/mod/hvp/xapi-collector.js'), true);
    }

    /**
     * Outputs h5p view
     */
    public function outputview() {
        global $PAGE;

        echo $PAGE->get_renderer('mod_hvp')->render_from_template('hvp/view', [
            'isDiv'     => $this->embedtype === 'div',
            'contentId' => $this->content['id']
        ]);
    }

    /**
     * Checks if content is valid, prints an error if not
     */
    public function validatecontent() {
        if ($this->content === null) {
            print_error('invalidhvp');
        }
    }
}