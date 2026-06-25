#!/usr/bin/env node
/**
 * FormFlow Pro - Accessibility Scanner
 * Scans PHP template files for common accessibility anti-patterns
 *
 * Checks for:
 * - Images without alt attributes
 * - Form inputs without associated labels
 * - Missing ARIA attributes on interactive elements
 * - Tables without headers
 * - Links without descriptive text
 * - Missing lang attribute on HTML output
 * - Buttons without accessible text
 */

const fs = require('fs');
const path = require('path');

const RED = '\x1b[0;31m';
const YELLOW = '\x1b[1;33m';
const GREEN = '\x1b[0;32m';
const BLUE = '\x1b[0;34m';
const NC = '\x1b[0m';

// Directories to scan
const DIRS_TO_SCAN = [
  'admin',
  'public',
  'includes',
  'formflow'
];

// File patterns to include
const FILE_PATTERNS = [/\.php$/];

// Issue counter
let issueCount = 0;
const issues = [];

/**
 * Recursively find PHP files in directories
 */
function findPhpFiles(dir) {
  const files = [];
  if (!fs.existsSync(dir)) return files;

  const items = fs.readdirSync(dir);
  items.forEach(item => {
    const fullPath = path.join(dir, item);
    const stat = fs.statSync(fullPath);

    if (stat.isDirectory()) {
      // Skip node_modules, vendor, etc.
      if (!['.git', 'node_modules', 'vendor', 'logs'].includes(item)) {
        files.push(...findPhpFiles(fullPath));
      }
    } else if (FILE_PATTERNS.some(pattern => pattern.test(item))) {
      files.push(fullPath);
    }
  });

  return files;
}

/**
 * Check for images without alt attributes
 */
function checkImageAltAttributes(content, filePath) {
  // PHP-interpolated attrs like src="<?php ... ?>" contain a ?> whose ">" would
  // truncate the attribute capture below and falsely report "missing alt".
  // Neutralize PHP blocks first (newline-preserving so line numbers stay accurate).
  content = content.replace(/<\?(?:php|=)?[\s\S]*?\?>/g, (m) => 'PHP' + '\n'.repeat((m.match(/\n/g) || []).length));
  // Match <img tags (both echo and literal HTML)
  const imgRegex = /<img\s+([^>]*?)>/gi;
  let match;

  while ((match = imgRegex.exec(content)) !== null) {
    const attributes = match[1];
    const hasAlt = /\balt\s*=/.test(attributes);
    const hasSrc = /\bsrc\s*=/.test(attributes);

    // Get line number (approximate)
    const lineNum = content.substring(0, match.index).split('\n').length;

    if (hasSrc && !hasAlt) {
      issues.push({
        severity: 'error',
        file: filePath,
        line: lineNum,
        issue: 'Image missing alt attribute',
        code: match[0].substring(0, 80)
      });
      issueCount++;
    }
  }
}

/**
 * Check for form inputs without associated labels
 */
function checkFormLabels(content, filePath) {
  content = content.replace(/<\?(?:php|=)?[\s\S]*?\?>/g, (m) => 'PHP' + '\n'.repeat((m.match(/\n/g) || []).length));
  // Match input fields (text, email, password, etc.)
  const inputRegex = /<input\s+type="(?:text|email|password|number|tel|url|date|time)"\s+([^>]*?)>/gi;
  let match;

  while ((match = inputRegex.exec(content)) !== null) {
    const attributes = match[1];
    const hasId = /\bid\s*=["']([^"']+)["']/.exec(attributes);
    const hasAriaLabel = /\baria-label\s*=/.test(attributes);
    const hasAriaLabelledby = /\baria-labelledby\s*=/.test(attributes);

    if (hasId) {
      const inputId = hasId[1];
      // Escape regex metachars — PHP-interpolated ids (e.g. id="<?php ... [ ?>") crashed
      // RegExp with "Unterminated group".
      const escapedId = inputId.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      // Check if there's a corresponding label
      const labelRegex = new RegExp(`for\\s*=\\s*["']${escapedId}["']`, 'i');
      if (!labelRegex.test(content) && !hasAriaLabel && !hasAriaLabelledby) {
        const lineNum = content.substring(0, match.index).split('\n').length;
        issues.push({
          severity: 'warning',
          file: filePath,
          line: lineNum,
          issue: `Form input#${inputId} not associated with label`,
          code: match[0].substring(0, 80)
        });
        issueCount++;
      }
    }
  }
}

/**
 * Check for interactive elements missing ARIA labels
 */
function checkAriaLabels(content, filePath) {
  // Check buttons without text or aria-label
  const buttonRegex = /<button\s+([^>]*?)>([^<]*?)<\/button>/gi;
  let match;

  while ((match = buttonRegex.exec(content)) !== null) {
    const attributes = match[1];
    const buttonText = match[2].trim();
    const hasAriaLabel = /\baria-label\s*=/.test(attributes);
    const hasAriaLabelledby = /\baria-labelledby\s*=/.test(attributes);
    const isHidden = /display\s*:\s*none|hidden/.test(attributes);

    if (!isHidden && !buttonText && !hasAriaLabel && !hasAriaLabelledby) {
      const lineNum = content.substring(0, match.index).split('\n').length;
      issues.push({
        severity: 'error',
        file: filePath,
        line: lineNum,
        issue: 'Button missing accessible label (text or aria-label)',
        code: match[0].substring(0, 80)
      });
      issueCount++;
    }
  }
}

/**
 * Check for tables without headers
 */
function checkTableHeaders(content, filePath) {
  const tableRegex = /<table[^>]*>[\s\S]*?<\/table>/gi;
  let match;

  while ((match = tableRegex.exec(content)) !== null) {
    const tableContent = match[0];
    const hasHeadOrTh = /<(thead|th)\b/i.test(tableContent);

    if (!hasHeadOrTh) {
      const lineNum = content.substring(0, match.index).split('\n').length;
      issues.push({
        severity: 'warning',
        file: filePath,
        line: lineNum,
        issue: 'Table missing thead or th header elements',
        code: match[0].substring(0, 80)
      });
      issueCount++;
    }
  }
}

/**
 * Check for links with non-descriptive text
 */
function checkLinkText(content, filePath) {
  const linkRegex = /<a\s+([^>]*?)>([^<]*?)<\/a>/gi;
  let match;

  while ((match = linkRegex.exec(content)) !== null) {
    const attributes = match[1];
    const linkText = match[2].trim().toLowerCase();
    const hasTitle = /\btitle\s*=/.test(attributes);
    const hasAriaLabel = /\baria-label\s*=/.test(attributes);

    // Check for non-descriptive link text
    const nonDescriptivePatterns = ['^click here$', '^read more$', '^learn more$', '^here$', '^link$', '^more$'];
    const isNonDescriptive = nonDescriptivePatterns.some(pattern => new RegExp(pattern).test(linkText));

    if (isNonDescriptive && !hasTitle && !hasAriaLabel) {
      const lineNum = content.substring(0, match.index).split('\n').length;
      issues.push({
        severity: 'warning',
        file: filePath,
        line: lineNum,
        issue: `Link has non-descriptive text: "${linkText}"`,
        code: match[0].substring(0, 80)
      });
      issueCount++;
    }
  }
}

/**
 * Check for HTML output missing lang attribute
 */
function checkHtmlLangAttribute(content, filePath) {
  // Only check main layout/HTML files
  if (!filePath.includes('layout') && !filePath.includes('template') && !filePath.includes('index')) {
    return;
  }

  const htmlRegex = /<html[^>]*>/i;
  const match = htmlRegex.exec(content);

  if (match) {
    const hasLang = /\blang\s*=/.test(match[0]);
    if (!hasLang && content.includes('<?php')) {
      const lineNum = content.substring(0, match.index).split('\n').length;
      issues.push({
        severity: 'info',
        file: filePath,
        line: lineNum,
        issue: 'HTML element should have lang attribute',
        code: match[0]
      });
    }
  }
}

/**
 * Check for SVG without title or aria-label
 */
function checkSvgAccessibility(content, filePath) {
  const svgRegex = /<svg[^>]*>[\s\S]*?<\/svg>/gi;
  let match;

  while ((match = svgRegex.exec(content)) !== null) {
    const svgContent = match[0];
    const hasTitle = /<title/.test(svgContent);
    const hasAriaLabel = /aria-label\s*=/.test(svgContent);
    const hasAriaHidden = /aria-hidden\s*=\s*["']true["']/.test(svgContent);

    if (!hasAriaHidden && !hasTitle && !hasAriaLabel && !svgContent.includes('icon')) {
      const lineNum = content.substring(0, match.index).split('\n').length;
      issues.push({
        severity: 'info',
        file: filePath,
        line: lineNum,
        issue: 'SVG should have title, aria-label, or aria-hidden=true',
        code: match[0].substring(0, 80)
      });
    }
  }
}

/**
 * Check for color-only information conveyance
 */
function checkColorContrast(content, filePath) {
  // Simple check: look for patterns where color might be the only indicator
  const colorOnlyPatterns = [
    /style\s*=\s*["'][^"']*color\s*:[^"']*["'][^>]*>[^<]*(?:error|warning|success|info|danger)[^<]*<\/[^>]*>/gi
  ];

  colorOnlyPatterns.forEach(pattern => {
    let match;
    while ((match = pattern.exec(content)) !== null) {
      const lineNum = content.substring(0, match.index).split('\n').length;
      issues.push({
        severity: 'info',
        file: filePath,
        line: lineNum,
        issue: 'Status information should not rely on color alone',
        code: match[0].substring(0, 80)
      });
    }
  });
}

/**
 * Scan a single file
 */
function scanFile(filePath) {
  try {
    const content = fs.readFileSync(filePath, 'utf8');

    checkImageAltAttributes(content, filePath);
    checkFormLabels(content, filePath);
    checkAriaLabels(content, filePath);
    checkTableHeaders(content, filePath);
    checkLinkText(content, filePath);
    checkHtmlLangAttribute(content, filePath);
    checkSvgAccessibility(content, filePath);
    checkColorContrast(content, filePath);
  } catch (error) {
    console.error(`Error scanning ${filePath}: ${error.message}`);
  }
}

/**
 * Run the scan
 */
function run() {
  console.log(`${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}`);
  console.log(`${BLUE}  FormFlow Pro - Accessibility Scanner${NC}`);
  console.log(`${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}`);
  console.log('');

  let totalFiles = 0;

  DIRS_TO_SCAN.forEach(dir => {
    const fullPath = path.join(process.cwd(), dir);
    const files = findPhpFiles(fullPath);
    totalFiles += files.length;

    files.forEach(file => {
      scanFile(file);
    });
  });

  console.log(`${BLUE}Scanned ${totalFiles} PHP files${NC}`);
  console.log('');

  if (issues.length === 0) {
    console.log(`${GREEN}✓ No accessibility issues found!${NC}`);
    return 0;
  }

  // Group issues by severity
  const errors = issues.filter(i => i.severity === 'error');
  const warnings = issues.filter(i => i.severity === 'warning');
  const infos = issues.filter(i => i.severity === 'info');

  // Display errors
  if (errors.length > 0) {
    console.log(`${RED}Errors (${errors.length}):${NC}`);
    errors.forEach(issue => {
      console.log(`  ${RED}✗${NC} ${path.relative(process.cwd(), issue.file)}:${issue.line}`);
      console.log(`    ${issue.issue}`);
      console.log(`    Code: ${issue.code}`);
      console.log('');
    });
  }

  // Display warnings
  if (warnings.length > 0) {
    console.log(`${YELLOW}Warnings (${warnings.length}):${NC}`);
    warnings.forEach(issue => {
      console.log(`  ${YELLOW}!${NC} ${path.relative(process.cwd(), issue.file)}:${issue.line}`);
      console.log(`    ${issue.issue}`);
      console.log(`    Code: ${issue.code}`);
      console.log('');
    });
  }

  // Display info messages (limited output)
  if (infos.length > 0 && infos.length <= 10) {
    console.log(`${BLUE}Info (${infos.length}):${NC}`);
    infos.forEach(issue => {
      console.log(`  ${BLUE}ℹ${NC} ${path.relative(process.cwd(), issue.file)}:${issue.line}`);
      console.log(`    ${issue.issue}`);
      console.log('');
    });
  } else if (infos.length > 10) {
    console.log(`${BLUE}Info (${infos.length}):${NC}`);
    console.log(`  ${BLUE}ℹ${NC} Run with verbose flag to see all info messages`);
    console.log('');
  }

  // Summary
  console.log(`${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}`);
  console.log(`Total issues: ${RED}${errors.length} errors${NC}, ${YELLOW}${warnings.length} warnings${NC}, ${BLUE}${infos.length} info${NC}`);
  console.log(`${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}`);

  // Exit with error code if there are critical issues
  return errors.length > 0 ? 1 : 0;
}

process.exit(run());
