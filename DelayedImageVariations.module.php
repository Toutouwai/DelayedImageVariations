<?php namespace ProcessWire;

class DelayedImageVariations extends WireData implements Module {

	/**
	 * Init
	 */
	public function init() {
		$this->addHookBefore('ProcessPageView::pageNotFound', $this, 'beforePageNotFound', ['priority' => 90]);
	}

	/**
	 * Ready
	 */
	public function ready() {
		$this->addHookBefore('Pageimage::size', $this, 'beforeSize', ['priority' => 190]);
		$this->addHookBefore('Pageimages::delete', $this, 'beforePageimagesDelete');
	}

	/**
	 * Before ProcessPageView::pageNotFound
	 *
	 * @param HookEvent $event
	 */
	protected function beforePageNotFound(HookEvent $event) {
		$url = $event->arguments(1);
		if(!$url) return;
		$config = $event->wire()->config;
		$files = $event->wire()->files;
		// Only if the URL is to file within the /site/assets/files/ directory
		if(strpos($url, $config->urls->files) !== 0) return;
		// Only if there is a corresponding queue file
		$root = rtrim($config->paths->root, '/');
		$queue_filename = $root . $url . '.queue';
		if(!is_file($queue_filename)) return;

		// Replace the hooked method
		$event->replace = true;

		// Cancel hooks because this is no longer a 404
		$event->cancelHooks = true;

		// Get the settings from the queue file then delete it
		$json = $files->fileGetContents($queue_filename);
		$settings = wireDecodeJSON($json);
		$files->unlink($queue_filename);

		// Get the original Pageimage
		$pm = $this->wire()->pages(1)->filesManager;
		$pageimage = $pm->getFile($settings['original']);
		if(!$pageimage) return;

		// Create the variation
		$settings['options']['noDelay'] = true;
		$variation = $pageimage->size($settings['width'], $settings['height'], $settings['options']);

		// Output the variation image data
		$image_info = getimagesize($variation->filename);
		header('Content-Type: ' . $image_info['mime']);
		header('Content-Length: ' . filesize($variation->filename));
		readfile($variation->filename);
	}

	/**
	 * Before Pageimage::size
	 *
	 * @param HookEvent $event
	 */
	protected function beforeSize(HookEvent $event) {
		/** @var Pageimage $pageimage */
		$pageimage = $event->object;
		$width = $event->arguments(0);
		$height = $event->arguments(1);
		$options = $event->arguments(2);
		if(!is_array($options)) $options = $pageimage->sizeOptionsToArray($options);
		$files = $this->wire()->files;

		// Return if noDelay is set in options
		if(!empty($options['noDelay'])) return;

		// Return if delayed variation is not allowed for this Pageimage
		if(!$this->allowDelayedVariation($pageimage, $width, $height, $options)) return;

		// Get basename
		$basename = $this->getVariationBasename($pageimage, $width, $height, $options);

		// Return if this size() call will not create a variation (e.g. SVG file)
		if(!$basename) return;

		// Return if the variation file already exists and the forceNew option isn't set
		$variation_filename = $pageimage->pagefiles->path() . $basename;
		if(is_file($variation_filename)) {
			if(!empty($options['forceNew'])) {
				// Delete the existing variation when forceNew option is set
				$files->unlink($variation_filename, true);
			} else {
				return;
			}
		}

		// Replace the hooked method
		$event->replace = true;

		// Cancel any other hooks because they will get a chance to fire on the delayed size() call
		$event->cancelHooks = true;

		// Write size() arguments to queue file in JSON format
		$queue_filename = $variation_filename . '.queue';
		$settings = [
			'original' => $pageimage->url,
			'width' => $width,
			'height' => $height,
			'options' => $options,
		];
		$json = json_encode($settings, JSON_UNESCAPED_UNICODE);
		$files->filePutContents($queue_filename, $json);

		// Clone original Pagefile for variation and set properties
		$variation = clone $pageimage;
		$variation->setFilename($variation_filename);
		$variation->setOriginal($pageimage);
		$event->return = $variation;
	}

	/**
	 * Before Pageimages::delete
	 * Delete any queue files that relate to the deleted image
	 *
	 * @param HookEvent $event
	 */
	protected function beforePageimagesDelete(HookEvent $event) {
		/** @var Pageimage $pageimage */
		$pageimage = $event->arguments(0);
		$files = $this->wire()->files;
		$filename_start = str_replace($pageimage->ext, '', $pageimage->filename);
		$candidates = $files->find($pageimage->pagefiles->path(), ['extensions' => 'queue']);
		foreach($candidates as $candidate) {
			if(strpos($candidate, $filename_start) !== 0) continue;
			$files->unlink($candidate);
		}
	}

	/**
	 * Is a delayed variation allowed for this Pageimage?
	 *
	 * @param Pageimage $pageimage
	 * @param int $width
	 * @param int $height
	 * @param array|string|int $options
	 * @return bool
	 */
	public function ___allowDelayedVariation(Pageimage $pageimage, $width, $height, $options) {
		// Skip admin thumbnail
		if($this->wire()->config->admin && ($width === 260 || $height === 260)) return false;
		return true;
	}

	/**
	 * Get the basename that Pageimage::size() would give to a variation
	 * Almost all the code in this method is copied from Pageimage::size() but $this is replaced with $pageimage
	 * Some redundant lines are commented out
	 *
	 * @param Pageimage $pageimage
	 * @param int $width
	 * @param int $height
	 * @param array|string|int $options
	 * @return string
	 */
	protected function getVariationBasename(Pageimage $pageimage, $width, $height, $options) {

		if($pageimage->ext === 'svg') return '';

		// START: copied from Pageimage::size()

		if(!is_array($options)) $options = $this->sizeOptionsToArray($options);

		// originally requested options
//		$requestOptions = $options;

		// default options
		$defaultOptions = array(
			'upscaling' => true,
			'cropping' => true,
			'interlace' => false,
			'sharpening' => 'soft',
			'quality' => 90,
			'hidpiQuality' => 40,
			'webpQuality' => 90,
			'webpAdd' => false,
			'webpName' => '', // use this for the webp file basename rather than mirroring from the jpg/png
			'webpOnly' => false, // only keep the webp version (requires webpAdd option)
			'suffix' => array(), // can be array of suffixes or string of 1 suffix
			'forceNew' => false,  // force it to create new image even if already exists
			'hidpi' => false,
			'cleanFilename' => false, // clean filename of historial resize information
			'rotate' => 0,
			'flip' => '',
			'nameWidth' => null, // override width to use for filename, int when populated
			'nameHeight' => null,  // override height to use for filename, int when populated
			'focus' => true, // allow single dimension resizes to use focus area?
			'zoom' => null, // zoom override, used only if focus is applicable, int when populated
			'allowOriginal' => false, // Return original image if already at requested dimensions? (must be only specified option)
		);

//		$files = $this->wire()->files;
		$config = $this->wire()->config;

		$debug = $config->debug;
		$configOptions = $config->imageSizerOptions;
		$webpOptions = $config->webpOptions;
//		$createdVariationHookData = null; // populated as array only when new variation created (for createdVariation hook)

		if(!empty($webpOptions['quality'])) $defaultOptions['webpQuality'] = $webpOptions['quality'];

		if(!is_array($configOptions)) $configOptions = array();
		$options = array_merge($defaultOptions, $configOptions, $options);
		if($options['cropping'] === 1) $options['cropping'] = true;

		$width = (int) $width;
		$height = (int) $height;

//		if($options['allowOriginal'] && count($requestOptions) === 1) {
//			if((!$width || $this->width() == $width) && (!$height || $this->height() == $height)) {
//				// return original image if already at requested width/height
//				return $this;
//			}
//		}

		if($options['cropping'] === true
			&& empty($options['cropExtra'])
			&& $options['focus'] && $pageimage->hasFocus
			&& $width && $height) {
			// crop to focus area
			$focus = $pageimage->focus();
			if(is_int($options['zoom'])) $focus['zoom'] = $options['zoom']; // override
			$options['cropping'] = array("$focus[left]%", "$focus[top]%", "$focus[zoom]");
			$crop = ''; // do not add suffix

		} else if(is_string($options['cropping'])
			&& strpos($options['cropping'], 'x') === 0
			&& preg_match('/^x(\d+)[yx](\d+)/', $options['cropping'], $matches)) {
			$options['cropping'] = true;
			$options['cropExtra'] = array((int) $matches[1], (int) $matches[2], $width, $height);
			$crop = '';

		} else {
			$crop = ImageSizer::croppingValueStr($options['cropping']);
		}

		if(!is_array($options['suffix'])) {
			// convert to array
			$options['suffix'] = empty($options['suffix']) ? array() : explode(' ', $options['suffix']);
		}

		if($options['rotate'] && !in_array(abs((int) $options['rotate']), array(90, 180, 270))) {
			$options['rotate'] = 0;
		}
		if($options['rotate']) {
			$options['suffix'][] = ($options['rotate'] > 0 ? "rot" : "tor") . abs($options['rotate']);
		}
		if($options['flip']) {
			$options['suffix'][] = strtolower(substr($options['flip'], 0, 1)) == 'v' ? 'flipv' : 'fliph';
		}

		$suffixStr = '';
		if(!empty($options['suffix'])) {
			$suffix = $options['suffix'];
			sort($suffix);
			foreach($suffix as $key => $s) {
				$s = strtolower($this->wire()->sanitizer->fieldName($s));
				if(empty($s)) {
					unset($suffix[$key]);
				} else {
					$suffix[$key] = $s;
				}
			}
			if(count($suffix)) $suffixStr = '-' . implode('-', $suffix);
		}

		if($options['hidpi']) {
			$suffixStr .= '-hidpi';
			if($options['hidpiQuality']) $options['quality'] = $options['hidpiQuality'];
		}

		$originalName = $pageimage->basename();
		// determine basename without extension, i.e. myfile
		$basename = basename($originalName, "." . $pageimage->ext());
//		$originalSize = $debug ? @filesize($pageimage->filename) : 0;

		if($options['cleanFilename'] && strpos($basename, '.') !== false) {
			$basename = substr($basename, 0, strpos($basename, '.'));
		}

		// filename uses requested width/height unless another specified via nameWidth or nameHeight options
		$nameWidth = is_int($options['nameWidth']) ? $options['nameWidth'] : $width;
		$nameHeight = is_int($options['nameHeight']) ? $options['nameHeight'] : $height;

		// i.e. myfile.100x100.jpg or myfile.100x100nw-suffix1-suffix2.jpg
		$basenameNoExt = $basename . '.' . $nameWidth . 'x' . $nameHeight . $crop . $suffixStr;  // basename without ext
		$basename = $basenameNoExt . '.' . $pageimage->ext(); // basename with ext

		// END: copied from Pageimage::size()

		return $basename;
	}

	/**
	 * Install
	 */
	public function ___install() {
		// Warn if older UniqueImageVariations module is installed
		$modules = $this->wire()->modules;
		if($modules->isInstalled('UniqueImageVariations')) {
			$info = $modules->getModuleInfo('UniqueImageVariations');
			if(version_compare($info['version'], '0.1.6', '<')) {
				$this->wire()->warning('Please update UniqueImageVariations to v0.1.6 or newer for compatibility with DelayedImageVariations.', Notice::noGroup);
			}
		}
	}

}
