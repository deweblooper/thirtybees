<?php

class OverridePatchCore
{
    /**
     * Add all methods in a module override to the override class
     *
     * @param $moduleName
     * @param $moduleVersion
     * @param string $classname
     *
     * @return bool
     * @throws PrestaShopException
     * @throws ReflectionException
     * @throws Exception
     * @since   1.0.0
     * @version 1.0.0 Initial version
     */
    public function installOverride($moduleName, $moduleVersion, $classname)
    {
        $origPath = $path = PrestaShopAutoload::getInstance()->getClassPath($classname.'Core');
        if (!$path) {
            $path = 'modules'.DIRECTORY_SEPARATOR.$classname.DIRECTORY_SEPARATOR.$classname.'.php';
        }
        $pathOverride = _PS_MODULE_DIR_.$moduleName.'/'.'override'.DIRECTORY_SEPARATOR.$path;

        if (!file_exists($pathOverride)) {
            return false;
        } else {
            file_put_contents($pathOverride, preg_replace('#(\r\n|\r)#ism', "\n", file_get_contents($pathOverride)));
        }

        $patternEscapeCom = '#(^\s*?\/\/.*?\n|\/\*(?!\n\s+\* module:.*?\* date:.*?\* version:.*?\*\/).*?\*\/)#ism';
        // Check if there is already an override file, if not, we just need to copy the file
        if ($file = PrestaShopAutoload::getInstance()->getClassPath($classname)) {
            // Check if override file is writable
            $overridePath = _PS_ROOT_DIR_.'/'.$file;

            if ((!file_exists($overridePath) && !is_writable(dirname($overridePath))) || (file_exists($overridePath) && !is_writable($overridePath))) {
                throw new Exception(sprintf(Tools::displayError('file (%s) not writable'), $overridePath));
            }

            // Get a uniq id for the class, because you can override a class (or remove the override) twice in the same session and we need to avoid redeclaration
            do {
                $uniq = uniqid();
            } while (class_exists($classname.'OverrideOriginal_remove', false));

            // Make a reflection of the override class and the module override class
            $overrideFile = file($overridePath);
            if (empty($overrideFile)) {
                // class_index was out of sync, so we just create a new override on the fly
                $overrideFile = [
                    "<?php\n",
                    "class {$classname} extends {$classname}Core\n",
                    "{\n",
                    "}\n",
                ];
            }
            $overrideFile = array_diff($overrideFile, ["\n"]);
            eval(preg_replace(['#^\s*<\?(?:php)?#', '#class\s+'.$classname.'\s+extends\s+([a-z0-9_]+)(\s+implements\s+([a-z0-9_]+))?#i'], [' ', 'class '.$classname.'OverrideOriginal'.$uniq], implode('', $overrideFile)));
            $overrideClass = new ReflectionClass($classname.'OverrideOriginal'.$uniq);

            $moduleFile = file($pathOverride);
            $moduleFile = array_diff($moduleFile, ["\n"]);
            eval(preg_replace(['#^\s*<\?(?:php)?#', '#class\s+'.$classname.'(\s+extends\s+([a-z0-9_]+)(\s+implements\s+([a-z0-9_]+))?)?#i'], [' ', 'class '.$classname.'Override'.$uniq], implode('', $moduleFile)));
            $moduleClass = new ReflectionClass($classname.'Override'.$uniq);

            // Check if none of the methods already exists in the override class
            foreach ($moduleClass->getMethods() as $method) {
                if ($overrideClass->hasMethod($method->getName())) {
                    $methodOverride = $overrideClass->getMethod($method->getName());
                    if (preg_match('/module: (.*)/ism', $overrideFile[$methodOverride->getStartLine() - 5], $name) && preg_match('/date: (.*)/ism', $overrideFile[$methodOverride->getStartLine() - 4], $date) && preg_match('/version: ([0-9.]+)/ism', $overrideFile[$methodOverride->getStartLine() - 3], $version)) {
                        if ($name[1] !== $moduleName || $version[1] !== $moduleVersion) {
                            throw new Exception(sprintf(Tools::displayError('The method %1$s in the class %2$s is already overridden by the module %3$s version %4$s at %5$s.'), $method->getName(), $classname, $name[1], $version[1], $date[1]));
                        }

                        continue;
                    }
                    throw new Exception(sprintf(Tools::displayError('The method %1$s in the class %2$s is already overridden.'), $method->getName(), $classname));
                }

                $moduleFile = preg_replace('/((:?public|private|protected)\s+(static\s+)?function\s+(?:\b'.$method->getName().'\b))/ism', "/*\n    * module: ".$moduleName."\n    * date: ".date('Y-m-d H:i:s')."\n    * version: ".$moduleVersion."\n    */\n    $1", $moduleFile);
                if ($moduleFile === null) {
                    throw new Exception(sprintf(Tools::displayError('Failed to override method %1$s in class %2$s.'), $method->getName(), $classname));
                }
            }

            // Check if none of the properties already exists in the override class
            foreach ($moduleClass->getProperties() as $property) {
                if ($overrideClass->hasProperty($property->getName())) {
                    throw new Exception(sprintf(Tools::displayError('The property %1$s in the class %2$s is already defined.'), $property->getName(), $classname));
                }

                $moduleFile = preg_replace('/((?:public|private|protected)\s)\s*(static\s)?\s*(\$\b'.$property->getName().'\b)/ism', "/*\n    * module: ".$moduleName."\n    * date: ".date('Y-m-d H:i:s')."\n    * version: ".$moduleVersion."\n    */\n    $1$2$3", $moduleFile);
                if ($moduleFile === null) {
                    throw new Exception(sprintf(Tools::displayError('Failed to override property %1$s in class %2$s.'), $property->getName(), $classname));
                }
            }

            foreach ($moduleClass->getConstants() as $constant => $value) {
                if ($overrideClass->hasConstant($constant)) {
                    throw new Exception(sprintf(Tools::displayError('The constant %1$s in the class %2$s is already defined.'), $constant, $classname));
                }

                $moduleFile = preg_replace('/(const\s)\s*(\b'.$constant.'\b)/ism', "/*\n    * module: ".$moduleName."\n    * date: ".date('Y-m-d H:i:s')."\n    * version: ".$moduleVersion."\n    */\n    $1$2", $moduleFile);
                if ($moduleFile === null) {
                    throw new Exception(sprintf(Tools::displayError('Failed to override constant %1$s in class %2$s.'), $constant, $classname));
                }
            }

            // Insert the methods from module override in override
            $copyFrom = array_slice($moduleFile, $moduleClass->getStartLine() + 1, $moduleClass->getEndLine() - $moduleClass->getStartLine() - 2);
            array_splice($overrideFile, $overrideClass->getEndLine() - 1, 0, $copyFrom);
            $code = implode('', $overrideFile);

            file_put_contents($overridePath, preg_replace($patternEscapeCom, '', $code));
        } else {
            $overrideSrc = $pathOverride;

            $overrideDest = _PS_ROOT_DIR_.DIRECTORY_SEPARATOR.'override'.DIRECTORY_SEPARATOR.$path;
            $dirName = dirname($overrideDest);

            if (!$origPath && !is_dir($dirName)) {
                $definedUmask = defined('_TB_UMASK_') ? _TB_UMASK_ : 0000;
                $oldumask = umask($definedUmask);
                @mkdir($dirName, 0777);
                umask($oldumask);
            }

            if (!is_writable($dirName)) {
                throw new Exception(sprintf(Tools::displayError('directory (%s) not writable'), $dirName));
            }
            $moduleFile = file($overrideSrc);
            $moduleFile = array_diff($moduleFile, ["\n"]);

            if ($origPath) {
                do {
                    $uniq = uniqid();
                } while (class_exists($classname.'OverrideOriginal_remove', false));
                eval(preg_replace(['#^\s*<\?(?:php)?#', '#class\s+'.$classname.'(\s+extends\s+([a-z0-9_]+)(\s+implements\s+([a-z0-9_]+))?)?#i'], [' ', 'class '.$classname.'Override'.$uniq], implode('', $moduleFile)));
                $moduleClass = new ReflectionClass($classname.'Override'.$uniq);

                // For each method found in the override, prepend a comment with the module name and version
                foreach ($moduleClass->getMethods() as $method) {
                    $moduleFile = preg_replace('/((:?public|private|protected)\s+(static\s+)?function\s+(?:\b'.$method->getName().'\b))/ism', "/*\n    * module: ".$moduleName."\n    * date: ".date('Y-m-d H:i:s')."\n    * version: ".$moduleVersion."\n    */\n    $1", $moduleFile);
                    if ($moduleFile === null) {
                        throw new Exception(sprintf(Tools::displayError('Failed to override method %1$s in class %2$s.'), $method->getName(), $classname));
                    }
                }

                // Same loop for properties
                foreach ($moduleClass->getProperties() as $property) {
                    $moduleFile = preg_replace('/((?:public|private|protected)\s)\s*(static\s)?\s*(\$\b'.$property->getName().'\b)/ism', "/*\n    * module: ".$moduleName."\n    * date: ".date('Y-m-d H:i:s')."\n    * version: ".$moduleVersion."\n    */\n    $1$2$3", $moduleFile);
                    if ($moduleFile === null) {
                        throw new Exception(sprintf(Tools::displayError('Failed to override property %1$s in class %2$s.'), $property->getName(), $classname));
                    }
                }

                // Same loop for constants
                foreach ($moduleClass->getConstants() as $constant => $value) {
                    $moduleFile = preg_replace('/(const\s)\s*(\b'.$constant.'\b)/ism', "/*\n    * module: ".$moduleName."\n    * date: ".date('Y-m-d H:i:s')."\n    * version: ".$moduleVersion."\n    */\n    $1$2", $moduleFile);
                    if ($moduleFile === null) {
                        throw new Exception(sprintf(Tools::displayError('Failed to override constant %1$s in class %2$s.'), $constant, $classname));
                    }
                }
            }

            file_put_contents($overrideDest, preg_replace($patternEscapeCom, '', $moduleFile));

            // Invalidate opcache
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($overrideDest);
            }

            // Re-generate the class index
            Tools::generateIndex();
        }

        return true;
    }

    /**
     * @param $moduleName string module name
     * @param $classname string override classname
     *
     * @return bool
     * @throws PrestaShopException
     * @throws ReflectionException
     */
    public function removeOverride($moduleName, $classname)
    {
        $origPath = $path = PrestaShopAutoload::getInstance()->getClassPath($classname.'Core');

        if ($origPath && !$file = PrestaShopAutoload::getInstance()->getClassPath($classname)) {
            return true;
        } elseif (!$origPath && Module::getModuleIdByName($classname)) {
            $path = 'modules'.DIRECTORY_SEPARATOR.$classname.DIRECTORY_SEPARATOR.$classname.'.php';
        }

        // Check if override file is writable
        if ($origPath) {
            $overridePath = _PS_ROOT_DIR_.'/'.$file;
        } else {
            $overridePath = _PS_OVERRIDE_DIR_.$path;
        }

        if (!is_file($overridePath) || !is_writable($overridePath)) {
            return false;
        }

        file_put_contents($overridePath, preg_replace('#(\r\n|\r)#ism', "\n", file_get_contents($overridePath)));

        if ($origPath) {
            // Get a uniq id for the class, because you can override a class (or remove the override) twice in the same session and we need to avoid redeclaration
            do {
                $uniq = uniqid();
            } while (class_exists($classname.'OverrideOriginal_remove', false));

            // Make a reflection of the override class and the module override class
            $overrideFile = file($overridePath);

            eval(preg_replace(['#^\s*<\?(?:php)?#', '#class\s+'.$classname.'\s+extends\s+([a-z0-9_]+)(\s+implements\s+([a-z0-9_]+))?#i'], [' ', 'class '.$classname.'OverrideOriginal_remove'.$uniq], implode('', $overrideFile)));
            $overrideClass = new ReflectionClass($classname.'OverrideOriginal_remove'.$uniq);

            $moduleFile = file(_PS_MODULE_DIR_.$moduleName.'/override/'.$path);
            eval(preg_replace(['#^\s*<\?(?:php)?#', '#class\s+'.$classname.'(\s+extends\s+([a-z0-9_]+)(\s+implements\s+([a-z0-9_]+))?)?#i'], [' ', 'class '.$classname.'Override_remove'.$uniq], implode('', $moduleFile)));
            $moduleClass = new ReflectionClass($classname.'Override_remove'.$uniq);

            // Remove methods from override file
            foreach ($moduleClass->getMethods() as $method) {
                if (!$overrideClass->hasMethod($method->getName())) {
                    continue;
                }

                $method = $overrideClass->getMethod($method->getName());
                $length = $method->getEndLine() - $method->getStartLine() + 1;

                $moduleMethod = $moduleClass->getMethod($method->getName());

                $overrideFileOrig = $overrideFile;

                $origContent = preg_replace('/\s/', '', implode('', array_splice($overrideFile, $method->getStartLine() - 1, $length, array_pad([], $length, '#--remove--#'))));
                $moduleContent = preg_replace('/\s/', '', implode('', array_splice($moduleFile, $moduleMethod->getStartLine() - 1, $length, array_pad([], $length, '#--remove--#'))));

                $replace = true;
                if (preg_match('/\* module: ('.$moduleName.')/ism', $overrideFile[$method->getStartLine() - 5])) {
                    $overrideFile[$method->getStartLine() - 6] = $overrideFile[$method->getStartLine() - 5] = $overrideFile[$method->getStartLine() - 4] = $overrideFile[$method->getStartLine() - 3] = $overrideFile[$method->getStartLine() - 2] = '#--remove--#';
                    $replace = false;
                }

                if (md5($moduleContent) != md5($origContent) && $replace) {
                    $overrideFile = $overrideFileOrig;
                }
            }

            // Remove properties from override file
            foreach ($moduleClass->getProperties() as $property) {
                if (!$overrideClass->hasProperty($property->getName())) {
                    continue;
                }

                // Replace the declaration line by #--remove--#
                foreach ($overrideFile as $lineNumber => &$lineContent) {
                    if (preg_match('/(public|private|protected)\s+(static\s+)?(\$)?'.$property->getName().'/i', $lineContent)) {
                        if (preg_match('/\* module: ('.$moduleName.')/ism', $overrideFile[$lineNumber - 4])) {
                            $overrideFile[$lineNumber - 5] = $overrideFile[$lineNumber - 4] = $overrideFile[$lineNumber - 3] = $overrideFile[$lineNumber - 2] = $overrideFile[$lineNumber - 1] = '#--remove--#';
                        }
                        $lineContent = '#--remove--#';
                        break;
                    }
                }
            }

            // Remove properties from override file
            foreach ($moduleClass->getConstants() as $constant => $value) {
                if (!$overrideClass->hasConstant($constant)) {
                    continue;
                }

                // Replace the declaration line by #--remove--#
                foreach ($overrideFile as $lineNumber => &$lineContent) {
                    if (preg_match('/(const)\s+(static\s+)?(\$)?'.$constant.'/i', $lineContent)) {
                        if (preg_match('/\* module: ('.$moduleName.')/ism', $overrideFile[$lineNumber - 4])) {
                            $overrideFile[$lineNumber - 5] = $overrideFile[$lineNumber - 4] = $overrideFile[$lineNumber - 3] = $overrideFile[$lineNumber - 2] = $overrideFile[$lineNumber - 1] = '#--remove--#';
                        }
                        $lineContent = '#--remove--#';
                        break;
                    }
                }
            }

            $count = count($overrideFile);
            for ($i = 0; $i < $count; ++$i) {
                if (preg_match('/(^\s*\/\/.*)/i', $overrideFile[$i])) {
                    $overrideFile[$i] = '#--remove--#';
                } elseif (preg_match('/(^\s*\/\*)/i', $overrideFile[$i])) {
                    if (!preg_match('/(^\s*\* module:)/i', $overrideFile[$i + 1])
                        && !preg_match('/(^\s*\* date:)/i', $overrideFile[$i + 2])
                        && !preg_match('/(^\s*\* version:)/i', $overrideFile[$i + 3])
                        && !preg_match('/(^\s*\*\/)/i', $overrideFile[$i + 4])
                    ) {
                        for (; $overrideFile[$i] && !preg_match('/(.*?\*\/)/i', $overrideFile[$i]); ++$i) {
                            $overrideFile[$i] = '#--remove--#';
                        }
                        $overrideFile[$i] = '#--remove--#';
                    }
                }
            }

            // Rewrite nice code
            $code = '';
            foreach ($overrideFile as $line) {
                if ($line == '#--remove--#') {
                    continue;
                }

                $code .= $line;
            }

            $toDelete = preg_match('/<\?(?:php)?\s+(?:abstract|interface)?\s*?class\s+'.$classname.'\s+extends\s+'.$classname.'Core\s*?[{]\s*?[}]/ism', $code);
        }

        if (!isset($toDelete) || $toDelete) {
            unlink($overridePath);
        } else {
            file_put_contents($overridePath, $code);

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($overridePath);
            }
        }

        // Re-generate the class index
        Tools::generateIndex();

        return true;
    }

}