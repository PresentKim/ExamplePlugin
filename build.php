<?php
declare(strict_types=1);

const PLUGIN_STUB = '
<?php
echo "%s PocketMine-MP plugin v%s, developed by %s. Built on %s.
Build url : %s
----------------
";
if(extension_loaded("phar")){
	$phar = new \Phar(__FILE__);
	foreach($phar->getMetadata() as $key => $value){
		echo ucfirst($key) . ": " . (is_array($value) ? implode(", ", $value) : $value) . "\n";
	}
}
__HALT_COMPILER();
';
$opts = getopt("", ["buildurl:", "buildnum:"]);

function main() : void{
	global $opts;
	echo "== Plugin Build Script ==" . PHP_EOL;

	if(ini_get("phar.readonly") == 1){
		echo "Set phar.readonly to 0 with -dphar.readonly=0" . PHP_EOL;
		exit(1);
	}

	$includedPaths = ["src", "plugin.yml"];
	array_walk($includedPaths, function(&$path, $key){
		$realPath = realpath($path);
		if($realPath === false){
			echo "[ERROR] required directory `$path` does not exist or permission denied" . PHP_EOL;
			echo "[ERROR] have you installed composer dependencies?" . PHP_EOL;
			exit(1);
		}

		//Convert to absolute path for base path detection
		if(is_dir($realPath)){
			$path = rtrim($realPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		}
	});

	$basePath = rtrim(realpath(__DIR__), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
	//Convert included paths back to relative after we decide what the base path is
	$includedPaths = array_filter(array_map(function(string $path) use ($basePath) : string{
		return str_replace($basePath, '', $path);
	}, $includedPaths), function(string $v) : bool{
		return $v !== '';
	});

	if(!is_dir($basePath)){
		echo $basePath . " is not a folder" . PHP_EOL;
		return;
	}

	echo PHP_EOL;

	$metadata = generatePluginMetadataFromYml($basePath . "plugin.yml", $opts["buildnum"]);
	if($metadata === null) {
		echo "Missing entry point or plugin.yml" . PHP_EOL;
		exit(1);
	}
	file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . "version.txt", str_replace(' ', '', $metadata["version"]));

	$stub = sprintf(PLUGIN_STUB, $metadata["name"], $metadata["version"], $metadata["author"], date("r"), $opts["buildurl"]);

	@mkdir(__DIR__ . DIRECTORY_SEPARATOR . "releases");
	$pharPath = $metadata["name"] . "_v" . $metadata["version"] . ".phar";
	foreach(buildPhar(__DIR__ .DIRECTORY_SEPARATOR . "releases" . DIRECTORY_SEPARATOR . $pharPath, $basePath, $includedPaths, $metadata, $stub, \Phar::SHA1, \Phar::GZ) as $line){
		echo $line . PHP_EOL;
	}
}

/**
 * @param string[]    $strings
 * @param string|null $delim
 *
 * @return string[]
 */
function preg_quote_array(array $strings, string $delim = null) : array{
	return array_map(function(string $str) use ($delim) : string{ return preg_quote($str, $delim); }, $strings);
}

/**
 * @param string   $pharPath
 * @param string   $basePath
 * @param string[] $includedPaths
 * @param array    $metadata
 * @param string   $stub
 * @param int      $signatureAlgo
 * @param int|null $compression
 *
 * @return Generator|string[]
 */
function buildPhar(string $pharPath, string $basePath, array $includedPaths, array $metadata, string $stub, int $signatureAlgo = \Phar::SHA1, ?int $compression = null){
	if(file_exists($pharPath)){
		yield "Phar file already exists, overwriting...";
		try{
			\Phar::unlinkArchive($pharPath);
		}catch(\PharException $e){
			//unlinkArchive() doesn't like dodgy phars
			unlink($pharPath);
		}
	}

	yield "Adding files...";

	$start = microtime(true);
	$phar = new \Phar($pharPath);
	$phar->setMetadata($metadata);
	$phar->setStub($stub);
	$phar->setSignatureAlgorithm($signatureAlgo);
	$phar->startBuffering();

	//If paths contain any of these, they will be excluded
	$excludedSubstrings = preg_quote_array([
		realpath($pharPath), //don't add the phar to itself
	], '/');

	$folderPatterns = preg_quote_array([
		DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR,
		DIRECTORY_SEPARATOR . '.' //"Hidden" files, git dirs etc
	], '/')
		;

	//Only exclude these within the basedir, otherwise the project won't get built if it itself is in a directory that matches these patterns
	$basePattern = preg_quote(rtrim($basePath, DIRECTORY_SEPARATOR), '/');
	foreach($folderPatterns as $p){
		$excludedSubstrings[] = $basePattern . '.*' . $p;
	}

	$regex = sprintf('/^(?!.*(%s))^%s(%s).*/i',
		implode('|', $excludedSubstrings), //String may not contain any of these substrings
		preg_quote($basePath, '/'), //String must start with this path...
		implode('|', preg_quote_array($includedPaths, '/')) //... and must be followed by one of these relative paths, if any were specified. If none, this will produce a null capturing group which will allow anything.
	);

	$directory = new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS | \FilesystemIterator::CURRENT_AS_PATHNAME); //can't use fileinfo because of symlinks
	$iterator = new \RecursiveIteratorIterator($directory);
	$regexIterator = new \RegexIterator($iterator, $regex);

	$count = count($phar->buildFromIterator($regexIterator, $basePath));
        yield "Added $count files";

	if($compression !== null){
		yield "Checking for compressible files...";
		if(\Phar::canCompress($compression)){
			yield "Compressing all files";
			$phar->compressFiles($compression);
		}else{
			foreach($phar as $file => $finfo){
				/** @var \PharFileInfo $finfo */
				if($finfo->getSize() > (1024 * 512)){
					yield "Compressing " . $finfo->getFilename();
					$finfo->compress($compression);
				}
			}
		}
	}
	$phar->stopBuffering();

	yield "Done in " . round(microtime(true) - $start, 3) . "s";
}

function generatePluginMetadataFromYml(string $pluginYmlPath, $buildnum) : ?array{
	if(!file_exists($pluginYmlPath)){
		return null;
	}

	$pluginYml = yaml_parse_file($pluginYmlPath);
	$metadata = [
		"name" => $pluginYml["name"],
		"version" => $pluginYml["version"] . " #" . $buildnum,
		"main" => $pluginYml["main"],
		"api" => $pluginYml["api"],
		"depend" => $pluginYml["depend"] ?? "",
		"description" => $pluginYml["description"] ?? "",
		"author" => $pluginYml["author"] ?? "",
		"authors" => $pluginYml["authors"] ?? "",
		"website" => $pluginYml["website"] ?? "",
		"creationDate" => time()
	];
	return $metadata;
}
main();
