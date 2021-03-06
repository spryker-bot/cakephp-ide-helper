<?php
namespace IdeHelper\Annotator;

use Cake\Core\Configure;
use Cake\Utility\Inflector;
use Cake\View\View;
use Exception;
use IdeHelper\Annotation\AnnotationFactory;
use IdeHelper\View\Helper\DocBlockHelper;
use PHP_CodeSniffer\Files\File;
use RuntimeException;

class EntityAnnotator extends AbstractAnnotator {

	/**
	 * @var array|null
	 */
	protected static $typeMap;

	/**
	 * @var array
	 */
	protected static $typeMapDefaults = [
		'mediumtext' => 'string',
		'longtext' => 'string',
		'array' => 'array',
		'json' => 'array',
	];

	/**
	 * @param string $path Path to file.
	 * @return bool
	 * @throws \RuntimeException
	 */
	public function annotate($path) {
		$name = pathinfo($path, PATHINFO_FILENAME);
		if ($name === 'Entity') {
			return false;
		}

		$content = file_get_contents($path);

		$helper = new DocBlockHelper(new View());
		/** @var \Cake\Database\Schema\TableSchema $tableSchema */
		$tableSchema = $this->getConfig('schema');
		$columns = $tableSchema->columns();

		$schema = [];
		foreach ($columns as $column) {
			$row = $tableSchema->getColumn($column);
			$row['kind'] = 'column';
			$schema[$column] = $row;
		}

		$schema = $this->hydrateSchemaFromAssoc($schema);

		$propertyHintMap = $helper->buildEntityPropertyHintTypeMap($schema);
		$propertyHintMap = $this->buildExtendedEntityPropertyHintTypeMap($schema, $helper) + $propertyHintMap;
		$propertyHintMap += $this->buildVirtualPropertyHintTypeMap($content);

		$propertyHintMap = array_filter($propertyHintMap);

		$annotations = $helper->propertyHints($propertyHintMap);
		$associationHintMap = $helper->buildEntityAssociationHintTypeMap($schema);

		if ($associationHintMap) {
			$annotations = array_merge($annotations, $helper->propertyHints($associationHintMap));
		}

		foreach ($annotations as $key => $annotation) {
			$annotationObject = AnnotationFactory::createFromString($annotation);
			if (!$annotationObject) {
				throw new RuntimeException('Cannot factorize annotation `' . $annotation . '`');
			}

			$annotations[$key] = $annotationObject;
		}

		return $this->_annotate($path, $content, $annotations);
	}

	/**
	 * From Bake Plugin
	 *
	 * @param array $schema
	 *
	 * @return array
	 */
	protected function hydrateSchemaFromAssoc(array $schema) {
		/** @var \Cake\ORM\AssociationCollection|\Cake\ORM\Association[] $associations */
		$associations = $this->getConfig('associations');

		foreach ($associations as $association) {
			try {
				$entityClass = '\\' . ltrim($association->getTarget()->getEntityClass(), '\\');

				if ($entityClass === '\Cake\ORM\Entity') {
					$namespace = Configure::read('App.namespace');

					list($plugin) = pluginSplit($association->getTarget()->getRegistryAlias());
					if ($plugin !== null) {
						$namespace = $plugin;
					}
					$namespace = str_replace('/', '\\', trim($namespace, '\\'));

					$entityClass = $this->_entityName($association->getTarget()->getAlias());
					$entityClass = '\\' . $namespace . '\Model\Entity\\' . $entityClass;

					if (!class_exists($entityClass)) {
						$entityClass = '\Cake\ORM\Entity';
					}
				}

				$schema[$association->getProperty()] = [
					'kind' => 'association',
					'association' => $association,
					'type' => $entityClass
				];
			} catch (Exception $exception) {
				continue;
			}
		}

		return $schema;
	}

	/**
	 * Creates the proper entity name (singular) for the specified name
	 *
	 * @param string $name Name
	 * @return string Camelized and plural model name
	 */
	protected function _entityName($name) {
		return Inflector::singularize(Inflector::camelize($name));
	}

	/**
	 * @param array $propertySchema
	 * @param \IdeHelper\View\Helper\DocBlockHelper $helper
	 *
	 * @return array
	 */
	protected function buildExtendedEntityPropertyHintTypeMap(array $propertySchema, DocBlockHelper $helper) {
		$propertyHintMap = [];

		foreach ($propertySchema as $property => $info) {
			if ($info['kind'] === 'column') {
				$type = $this->columnTypeToHintType($info['type']);
				if ($type === null) {
					continue;
				}

				$propertyHintMap[$property] = $helper->columnTypeNullable($info, $type);
			}
		}

		return $propertyHintMap;
	}

	/**
	 * Converts a column type to its DocBlock type counterpart.
	 *
	 * @see \Cake\Database\Type
	 *
	 * @param string $type The column type.
	 * @return string|null The DocBlock type, or `null` for unsupported column types.
	 */
	protected function columnTypeToHintType($type) {
		if (static::$typeMap === null) {
			static::$typeMap = (array)Configure::read('IdeHelper.typeMap') + static::$typeMapDefaults;
		}

		if (isset(static::$typeMap[$type])) {
			return static::$typeMap[$type];
		}

		return null;
	}

	/**
	 * @param string $content
	 * @return array
	 */
	protected function buildVirtualPropertyHintTypeMap($content) {
		if (!preg_match('#\bfunction \_get[A-Z][a-zA-Z0-9]+\(\)#', $content)) {
			return [];
		}

		$file = $this->_getFile('', $content);

		$classIndex = $file->findNext(T_CLASS, 0);
		if ($classIndex === false) {
			return [];
		}

		$tokens = $file->getTokens();
		if (empty($tokens[$classIndex]['scope_closer'])) {
			return [];
		}

		$classEndIndex = $tokens[$classIndex]['scope_closer'];

		$properties = [];
		$startIndex = $classIndex;
		while ($startIndex < $classEndIndex) {
			$functionIndex = $file->findNext(T_FUNCTION, $startIndex + 1);
			if ($functionIndex === false) {
				break;
			}

			$methodNameIndex = $file->findNext(T_STRING, $functionIndex + 1);
			if ($methodNameIndex === false) {
				break;
			}

			$token = $tokens[$methodNameIndex];
			$methodName = $token['content'];

			$startIndex = $methodNameIndex + 1;

			if (!preg_match('#^\_get([A-Z][a-zA-Z0-9]+)$#', $methodName, $matches)) {
				continue;
			}

			$property = Inflector::underscore($matches[1]);

			$properties[$property] = $this->returnType($file, $tokens, $functionIndex);
		}

		return $properties;
	}

	/**
	 * @param \PHP_CodeSniffer\Files\File $file
	 * @param array $tokens
	 * @param int $functionIndex
	 * @return string
	 */
	protected function returnType(File $file, array $tokens, $functionIndex) {
		$firstTokenInLineIndex = $functionIndex;

		$line = $tokens[$functionIndex]['line'];

		while ($tokens[$firstTokenInLineIndex - 1]['line'] === $line) {
			$firstTokenInLineIndex--;
		}

		$docBlockCloseTagIndex = $this->_findDocBlockCloseTagIndex($file, $firstTokenInLineIndex);
		if (!$docBlockCloseTagIndex || empty($tokens[$docBlockCloseTagIndex]['comment_opener'])) {
			return $this->typeHint($file, $tokens, $functionIndex);
		}

		$docBlockOpenTagIndex = $tokens[$docBlockCloseTagIndex]['comment_opener'];

		return $this->extractReturnType($tokens, $docBlockOpenTagIndex, $docBlockCloseTagIndex);
	}

	/**
	 * @param \PHP_CodeSniffer\Files\File $file
	 * @param array $tokens
	 * @param int $functionIndex
	 *
	 * @return string
	 */
	protected function typeHint(File $file, array $tokens, $functionIndex) {
		$parenthesisCloseTagIndex = $tokens[$functionIndex]['parenthesis_closer'];
		$scopeOpenTagIndex = $tokens[$functionIndex]['scope_opener'];

		$typehintIndex = $file->findNext(T_STRING, $parenthesisCloseTagIndex + 1, $scopeOpenTagIndex);
		if ($typehintIndex === false) {
			return 'mixed';
		}

		$returnType = $tokens[$typehintIndex]['content'];

		$nullableIndex = $file->findNext(T_NULLABLE, $parenthesisCloseTagIndex + 1, $typehintIndex);

		if ($nullableIndex) {
			$returnType .= '|null';
		}

		return $returnType;
	}

	/**
	 * @param array $tokens
	 * @param int $docBlockOpenTagIndex
	 * @param int $docBlockCloseTagIndex
	 *
	 * @return string
	 */
	protected function extractReturnType(array $tokens, $docBlockOpenTagIndex, $docBlockCloseTagIndex) {
		for ($i = $docBlockOpenTagIndex + 1; $i < $docBlockCloseTagIndex; $i++) {

			if ($tokens[$i]['type'] !== 'T_DOC_COMMENT_TAG') {
				continue;
			}
			if ($tokens[$i]['content'] !== '@return') {
				continue;
			}

			$classNameIndex = $i + 2;

			if ($tokens[$classNameIndex]['type'] !== 'T_DOC_COMMENT_STRING') {
				continue;
			}

			$content = $tokens[$classNameIndex]['content'];

			//$appendix = '';
			$spaceIndex = strpos($content, ' ');
			if ($spaceIndex) {
				//$appendix = substr($content, $spaceIndex);
				$content = substr($content, 0, $spaceIndex);
			}

			return $content;
		}

		return 'mixed';
	}

}
