<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../api/lawgpt_premium.php';

class LawGptPremiumTest extends TestCase
{
    public function testExtractCaseDetailsFromMarkdown()
    {
        $markdown = <<<EOD
# [Main Legal Question / Issue]

## I. Issue
- [Clearly state the legal question(s)]

## II. Rule
- [State applicable laws, rules, or jurisprudence]

### Relevant Jurisprudence
- **G.R. No.:** 123456-789  
- **Case Title:** People of the Philippines vs. Juan Dela Cruz  
- **Date of Decision:** January 01, 2025  
- **Division / En Banc:** [Specify]  
- **Facts:**  
  - [Concise verified facts from Lawphil.net]  
- **Issue(s):**  
  - [Specific question(s) resolved by the Court]  
EOD;

        $expected = [
            'gr_number' => '123456-789',
            'case_title' => 'People of the Philippines vs. Juan Dela Cruz',
            'date_of_decision' => 'January 01, 2025',
        ];

        $result = extractCaseDetailsFromMarkdown($markdown);

        $this->assertEquals($expected, $result);
    }
}
