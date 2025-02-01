<?php

namespace ProcessWire;

/**
 * ProcessTodoMonitor Module
 * 
 * Scans template files for todo comments and displays them in the admin interface
 */
class ProcessTodoMonitor extends Process
{
  /** @var TodoScanner */
  private $scanner;

  /**
   * Module configuration
   * @return array Module information
   */
  public static function getModuleInfo()
  {
    return [
      'title' => 'TodoMonitor',
      'summary' => 'Scans template files for todo comments and displays them in the admin',
      'href' => 'https://webmanufaktur.net/',
      'version' => '0.0.6',
      'author' => 'Alexander Abelt',
      'license' => 'MIT',
      'autoload' => 'true',
      'singular' => 'true',
      'icon' => 'search',
      'page' => [
        'parent' => 'setup',
        'name' => 'todomonitor',
        'title' => 'Todo Monitor',
      ]
    ];
  }

  /**
   * Initialize the module
   */
  public function init()
  {
    $this->scanner = new TodoScanner();
    $this->addHook('LazyCron::every5Minutes', $this, 'lazyScan');
  }

  /**
   * Lazy cron handler to scan files every 5 minutes
   * @param HookEvent $event Hook event object
   */
  public function lazyScan(HookEvent $event)
  {
    $groupedItems = $this->scanner->getGroupedTodoItems();
    $this->wire('cache')->save('TodoMonitorResults', $groupedItems, 3600);
    $this->log()->save('TodoMonitor', 'Scanned templates and updated TodoMonitor results.');
  }

  /**
   * Main execution method
   * @return string HTML output of todo items
   */
  public function ___execute()
  {

    $grouped = $this->wire('cache')->get('TodoMonitorResults');
    if (!$grouped) {
      $grouped = $this->scanner->getGroupedTodoItems();
    }

    if (empty($grouped)) {
      return "<p>No entries found</p>";
    }

    $output = "";
    foreach ($grouped as $tag => $files) {
      if (empty($files)) continue;
      $output .= "<h2>$tag</h2>";
      foreach ($files as $filePath => $items) {
        $filePath = substr($filePath, strpos($filePath, '/site/templates/'));
        $output .= "<p><strong><em>$filePath</em></strong></p><table>";
        foreach ($items as $item) {
          $output .= "<tr><td><strong>Line {$item['line']}:</strong></td><td>" . htmlentities($item['comment']) . "</td></tr>";
        }
        $output .= "</table>";
      }
    }
    return $output;
  }
}

/**
 * TodoScanner Class
 * 
 * Handles file scanning and todo comment extraction
 */
class TodoScanner extends Wire
{
  /**
   * Scan directory for files with specific extensions
   * @param string $dir Directory path to scan
   * @return array List of file paths
   */
  protected function scanFiles($dir)
  {
    // Optional:
    // in `/site/config.php`
    // $config->todoMonitorExtensions = "php,css,js,twig";
    $extensions = $this->config->todoMonitorExtensions ? explode(',', str_replace(' ', '', $this->config->todoMonitorExtensions)) : ['php', 'css', 'twig', 'latte'];
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
      if ($file->isFile()) {
        $ext = strtolower($file->getExtension());
        if (in_array($ext, $extensions)) {
          $files[] = $file->getPathname();
        }
      }
    }
    return $files;
  }

  /**
   * Extract todo tags from a file
   * @param string $filepath Path to file
   * @return array Array of todo items with tags
   */
  protected function extractTagsFromFile($filepath)
  {
    $results = [];
    $content = file_get_contents($filepath);
    // Optional:
    // in `/site/config.php`
    // $config->todoMonitorKeywords = 'Todo,Note,Info,Bug,Review';
    $keywords = $this->config->todoMonitorKeywords ? explode(',', str_replace(' ', '', $this->config->todoMonitorKeywords)) : ['Bug', 'Todo', 'Info'];
    preg_match_all('/@(' . implode('|', $keywords) . ')\b(.*)/i', $content, $matches, PREG_SET_ORDER);
    foreach ($matches as $index => $match) {
      $tag = ucfirst(strtolower($match[1]));
      $text = trim($match[2]);
      $results[] = [
        'file' => $filepath,
        'tag' => $tag,
        'comment' => $text,
        'line' => $index + 1
      ];
    }
    return $results;
  }

  /**
   * Get all todo items grouped by tag
   * @return array Grouped todo items
   */
  public function getGroupedTodoItems()
  {
    $dir = $this->config->paths->templates;
    $allFiles = $this->scanFiles($dir);

    $grouped = [];
    foreach ($allFiles as $filepath) {
      $items = $this->extractTagsFromFile($filepath);
      foreach ($items as $item) {
        if (!isset($grouped[$item['tag']])) {
          $grouped[$item['tag']] = [];
        }
        if (!isset($grouped[$item['tag']][$item['file']])) {
          $grouped[$item['tag']][$item['file']] = [];
        }
        $grouped[$item['tag']][$item['file']][] = $item;
      }
    }
    return $grouped;
  }
}
