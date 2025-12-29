<?php
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class LawGptVerificationLogicTest extends TestCase
{
    /**
     * A helper function that mirrors the logic from lawgpt_premium.php to extract case details from a markdown string.
     * This is included here to test the verification logic in isolation.
     */
    private function extractCaseDetailsFromMarkdown(string $markdown): array
    {
        $details = [
            'gr_number' => null,
            'case_title' => null,
            'date_of_decision' => null,
        ];

        // Extract G.R. No.
        if (preg_match('/\*\*G\.R\. No\.:\*\*\s*([\w\d-]+)/i', $markdown, $matches)) {
            $details['gr_number'] = trim($matches[1]);
        }

        // Extract Case Title
        if (preg_match('/\*\*Case Title:\*\*\s*([^\*]+)/i', $markdown, $matches)) {
            $details['case_title'] = trim($matches[1]);
        }

        // Extract Date of Decision
        if (preg_match('/\*\*Date of Decision:\*\*\s*([^\*]+)/i', $markdown, $matches)) {
            $details['date_of_decision'] = trim($matches[1]);
        }

        return $details;
    }

    /**
     * A helper function that simulates the core verification logic introduced in lawgpt_premium.php.
     * It compares an original title with a verified title and returns true if a correction is needed.
     */
    private function needsCorrection(string $original_markdown, array $verified_data, float $threshold = 90.0): bool
    {
        $extracted_details = $this->extractCaseDetailsFromMarkdown($original_markdown);

        $original_title = $extracted_details['case_title'] ?? '';
        $verified_title = $verified_data['Case Title'] ?? null;
        $needs_correction = false;

        if ($original_title && $verified_title) {
            similar_text($original_title, $verified_title, $similarity_percent);
            if ($similarity_percent < $threshold) {
                $needs_correction = true;
            }
        }
        return $needs_correction;
    }

    public function testExtractCaseDetailsFromMarkdown()
    {
        $markdown = <<<EOD
# Legal Analysis

## IV. Conclusion

---

### Relevant Jurisprudence
- **G.R. No.:** GR-12345
- **Case Title:** *People of the Philippines vs. Juan Dela Cruz*
- **Date of Decision:** January 01, 2025
EOD;
        $details = $this->extractCaseDetailsFromMarkdown($markdown);

        $this->assertEquals('GR-12345', $details['gr_number']);
        $this->assertEquals('*People of the Philippines vs. Juan Dela Cruz*', $details['case_title']);
        $this->assertEquals('January 01, 2025', $details['date_of_decision']);
    }

    public function testCorrectionNeededWhenTitlesDifferSignificantly()
    {
        $original_markdown = "- **Case Title:** People vs. Jon Cruz";
        $verified_data = ['Case Title' => 'People of the Philippines vs. Juan Dela Cruz'];

        $this->assertTrue($this->needsCorrection($original_markdown, $verified_data), "Should need correction for significantly different titles.");
    }

    public function testNoCorrectionNeededWhenTitlesAreIdentical()
    {
        $original_markdown = "- **Case Title:** People of the Philippines vs. Juan Dela Cruz";
        $verified_data = ['Case Title' => 'People of the Philippines vs. Juan Dela Cruz'];

        $this->assertFalse($this->needsCorrection($original_markdown, $verified_data), "Should not need correction for identical titles.");
    }

    public function testNoCorrectionNeededForMinorTypo()
    {
        $original_markdown = "- **Case Title:** People of the Philippines vs. Juan Dela Crus"; // Typo: Crus vs Cruz
        $verified_data = ['Case Title' => 'People of the Philippines vs. Juan Dela Cruz'];

        // This should be > 90% similar, so no correction is needed.
        $this->assertFalse($this->needsCorrection($original_markdown, $verified_data), "Should not need correction for a minor typo.");
    }
    
    public function testCorrectionNeededForAbbreviation()
    {
        $original_markdown = "- **Case Title:** People of the Phils. vs. J. Dela Cruz";
        $verified_data = ['Case Title' => 'People of the Philippines vs. Juan Dela Cruz'];

        // "Phils." and "J." are significant changes that should trigger correction.
        $this->assertTrue($this->needsCorrection($original_markdown, $verified_data), "Should need correction when abbreviations are used.");
    }

    public function testNoCorrectionWhenCheckerProvidesNoTitle()
    {
        $original_markdown = "- **Case Title:** People vs. Juan Dela Cruz";
        $verified_data = ['Case Title' => null];

        $this->assertFalse($this->needsCorrection($original_markdown, $verified_data), "Should not correct if the checker fails to provide a title.");
    }
}

