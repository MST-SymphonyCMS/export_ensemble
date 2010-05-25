<?php

	Class extension_export_ensemble implements iExtension{

		public function about(){
			return (object)array(
				'name' => 'Export Ensemble',
				'version' => '2.0',
				'release-date' => '2010-05-21',
				'author' => (object)array(
					'name' => 'Alistair Kearney',
					'website' => 'http://alistairkearney.com',
					'email' => 'hi@alistairkearney.com'
				)
			);
		}
		
		public function getSubscribedDelegates(){
			return array(
				array(
					'page' => '/system/settings/extensions/',
					'delegate' => 'AddSettingsFieldsets',
					'callback' => 'cbAppendPreferences'
				)
			);
		}
		
		public function install(){
			if(!class_exists('ZipArchive')){
				throw new ExtensionException(__(
					'Export Ensemble cannot be installed, since the "ZipArchive" class is not available. Ensure that PHP was compiled with the --enable-zip flag.'
				));
			}
			return true;
		}

		public function cbAppendPreferences($context){
			
			if(isset($_POST['action']['export'])){
				$this->export($context);
			}
			
			$document = Administration::instance()->Page;
			
			$group = $document->createElement('fieldset');
			$group->setAttribute('class', 'settings');
			$group->appendChild($document->createElement('h3', __('Export Ensemble')));

			$div = $document->createElement('div', NULL, array('id' => 'file-actions', 'class' => 'label'));

			if(!class_exists('ZipArchive')){
				$div->appendChild(
					$document->createElement('p',  __('Warning: It appears you do not have the "ZipArchive" class available. Ensure that PHP was compiled with --enable-zip'))
				);
			}
			else{
				$div->appendChild($document->createElement('p', __('Packages entire site as a ".zip" archive for download.')));
				$div->appendChild($document->createElement('button', __('Create'), array('name' => 'action[export]', 'type' => 'submit')));	
			}

			$group->appendChild($div);
			$context['fieldsets'][] = $group;
						
		}		
		
		private function __addFolderToArchive(&$archive, $path, $parent=NULL){
			$iterator = new DirectoryIterator($path);
			foreach($iterator as $file){
				if($file->isDot() || preg_match('/^\./', $file->getFilename())) continue;
				
				elseif($file->isDir()){
					$this->__addFolderToArchive($archive, $file->getPathname(), $parent);
				}

				else $archive->addFile($file->getPathname(), ltrim(str_replace($parent, NULL, $file->getPathname()), '/'));
			}
		}
		
		private function export($context){
			$sql = NULL;
			
			require_once('lib/class.mysqldump.php');
			
			$dump = new MySQLDump(Symphony::Database());
			
			$tables = $dump->listTables(Symphony::Configuration()->db()->{'table-name-prefix'} . '%', false);
			
			$structure_tables = $tables;
			$data_tables = $tables;
			
			//remove some tables from the $data list
			$data_tables = array_flip($data_tables);
			unset($data_tables['tbl_cache']);
			unset($data_tables['tbl_forgotpass']);
			unset($data_tables['tbl_users']);
			unset($data_tables['tbl_sessions']);
			$data_tables = array_flip($data_tables);

			## Grab the schema
			foreach($structure_tables as $t){
				$sql .= $dump->export($t, MySQLDump::STRUCTURE_ONLY);
			}

			## Grab the data
			foreach($data_tables as $t){
				$sql .= $dump->export($t, MySQLDump::DATA_ONLY);
			}
			
			$sql = str_replace('`' . Symphony::Configuration()->db()->{'table-name-prefix'}, '`tbl_', $sql);
			$sql = preg_replace('/AUTO_INCREMENT=\d+/i', NULL, $sql);

			$archive = new ZipArchive;
			$res = $archive->open(TMP . '/ensemble.tmp.zip', ZipArchive::CREATE);

			if ($res === TRUE) {
				
				$this->__addFolderToArchive($archive, EXTENSIONS, DOCROOT);
				$this->__addFolderToArchive($archive, SYMPHONY, DOCROOT);
				$this->__addFolderToArchive($archive, WORKSPACE, DOCROOT);
				$this->__addFolderToArchive($archive, DOCROOT . '/install', DOCROOT);
				$this->__addFolderToArchive($archive, MANIFEST . '/templates', DOCROOT);

				$archive->addFromString('install/assets/install.sql', $sql);
				
				$archive->addFile(DOCROOT . '/index.php', 'index.php');
				
				$readme_files = glob(DOCROOT . '/README.*');
				if(is_array($readme_files) && !empty($readme_files)){
					foreach($readme_files as $filename){
						$archive->addFile($filename, basename($filename));	
					}
				}
				
				if(is_file(DOCROOT . '/README')) $archive->addFile(DOCROOT . '/README', 'README');
				if(is_file(DOCROOT . '/LICENCE')) $archive->addFile(DOCROOT . '/LICENCE', 'LICENCE');
				if(is_file(DOCROOT . '/update.php')) $archive->addFile(DOCROOT . '/update.php', 'update.php');
				
				$configuration_files = glob(CONF . '/*.xml');
				if(is_array($configuration_files) && !empty($configuration_files)){
					foreach($configuration_files as $filename){
						if(in_array(basename($filename), array('db.xml', 'core.xml'))) continue;
						$archive->addFile($filename, 'manifest/conf/' . basename($filename));
					}
				}
				
				$this->__addFolderToArchive($archive, MANIFEST . '/templates', DOCROOT);
				if(is_file(CONF . '/.htaccess')) $archive->addFile(CONF . '/.htaccess', 'manifest/conf/.htaccess');
				if(is_file(MANIFEST . '/extensions.xml')) $archive->addFile(MANIFEST . '/extensions.xml', 'manifest/extensions.xml');
			}
			
			$archive->close();

			header('Content-type: application/octet-stream');	
			header('Expires: ' . gmdate('D, d M Y H:i:s') . ' GMT');
			
		    header(
				sprintf(
					'Content-disposition: attachment; filename=%s-ensemble.zip', 
					Lang::createFilename(
						Symphony::Configuration()->core()->symphony->sitename
					)
				)
			);
			
		    header('Pragma: no-cache');
		
			readfile(TMP . '/ensemble.tmp.zip');
			unlink(TMP . '/ensemble.tmp.zip');
			exit();
			
		}

	}
	
	return 'extension_export_ensemble';