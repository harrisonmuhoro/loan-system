<?php
// lib/FileParser.php
class FileParser {
    
    /**
     * Parse uploaded file and extract user data
     * 
     * @param string $filePath Path to uploaded file
     * @param string $fileType File extension (pdf, xlsx, xls, csv)
     * @return array Array of user records
     * @throws Exception If parsing fails
     */
    public static function parseFile($filePath, $fileType) {
        switch (strtolower($fileType)) {
            case 'pdf':
                return self::parsePDF($filePath);
            case 'xlsx':
            case 'xls':
                return self::parseExcel($filePath);
            case 'csv':
                return self::parseCSV($filePath);
            default:
                throw new Exception('Unsupported file type: ' . $fileType);
        }
    }
    
    /**
     * Parse PDF file
     * Uses Smalot\PdfParser library
     */
    private static function parsePDF($filePath) {
        require_once __DIR__ . '/../vendor/autoload.php';
        
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText();
            
            // Extract data using regex patterns
            return self::extractUserDataFromText($text);
            
        } catch (Exception $e) {
            throw new Exception('PDF parsing failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Parse Excel file (XLSX/XLS)
     * Uses PhpSpreadsheet library
     */
    private static function parseExcel($filePath) {
        require_once __DIR__ . '/../vendor/autoload.php';
        
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            
            $data = [];
            $headers = [];
            $firstRow = true;
            
            foreach ($worksheet->getRowIterator() as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                
                $rowData = [];
                foreach ($cellIterator as $cell) {
                    $rowData[] = $cell->getValue();
                }
                
                // First row is headers
                if ($firstRow) {
                    $headers = array_map(function($h) {
                        return strtolower(trim($h));
                    }, $rowData);
                    $firstRow = false;
                    continue;
                }
                
                // Skip empty rows
                if (empty(array_filter($rowData))) {
                    continue;
                }
                
                // Map row data to headers
                $userData = [];
                foreach ($headers as $index => $header) {
                    $userData[$header] = isset($rowData[$index]) ? trim($rowData[$index]) : '';
                }
                
                // Normalize to expected field names
                $normalizedData = self::normalizeUserData($userData);
                if ($normalizedData) {
                    $data[] = $normalizedData;
                }
            }
            
            return $data;
            
        } catch (Exception $e) {
            throw new Exception('Excel parsing failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Parse CSV file
     */
    private static function parseCSV($filePath) {
        $data = [];
        $headers = [];
        $firstRow = true;
        
        if (($handle = fopen($filePath, 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                // First row is headers
                if ($firstRow) {
                    $headers = array_map(function($h) {
                        return strtolower(trim($h));
                    }, $row);
                    $firstRow = false;
                    continue;
                }
                
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }
                
                // Map row data to headers
                $userData = [];
                foreach ($headers as $index => $header) {
                    $userData[$header] = isset($row[$index]) ? trim($row[$index]) : '';
                }
                
                // Normalize to expected field names
                $normalizedData = self::normalizeUserData($userData);
                if ($normalizedData) {
                    $data[] = $normalizedData;
                }
            }
            fclose($handle);
        }
        
        return $data;
    }
    
    /**
     * Extract user data from plain text (for PDF)
     */
    private static function extractUserDataFromText($text) {
        $data = [];
        $lines = explode("\n", $text);
        
        // Try to detect table structure
        $currentUser = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Look for patterns
            // Name pattern: Letters and spaces
            if (preg_match('/^([A-Za-z\s]+)$/', $line, $matches)) {
                if (!empty($currentUser)) {
                    $data[] = $currentUser;
                }
                $currentUser = ['full_name' => trim($matches[1])];
            }
            
            // Email pattern
            if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $line, $matches)) {
                $currentUser['email'] = $matches[1];
            }
            
            // ID Number pattern (7-8 digits)
            if (preg_match('/\b(\d{7,8})\b/', $line, $matches)) {
                if (!isset($currentUser['id_number'])) {
                    $currentUser['id_number'] = $matches[1];
                }
            }
            
            // PF Number pattern (9-11 digits)
            if (preg_match('/\b(\d{9,11})\b/', $line, $matches)) {
                $currentUser['pf_number'] = $matches[1];
            }
        }
        
        // Add last user
        if (!empty($currentUser)) {
            $data[] = $currentUser;
        }
        
        return array_map([self::class, 'normalizeUserData'], $data);
    }
    
    /**
     * Normalize user data to expected field names
     */
    private static function normalizeUserData($data) {
        // Mapping of possible column names to standard field names
        $fieldMappings = [
            'full_name' => ['name', 'full name', 'fullname', 'staff name', 'employee name', 'full_name'],
            'email' => ['email', 'email address', 'e-mail', 'mail'],
            'id_number' => ['id', 'id number', 'id no', 'idnumber', 'id_number', 'identification number', 'national id'],
            'pf_number' => ['pf', 'pf number', 'pf no', 'pfnumber', 'pf_number', 'payroll number', 'staff number']
        ];
        
        $normalized = [];
        
        foreach ($fieldMappings as $standardField => $possibleNames) {
            foreach ($possibleNames as $name) {
                if (isset($data[$name]) && !empty($data[$name])) {
                    $normalized[$standardField] = $data[$name];
                    break;
                }
            }
        }
        
        // Return null if required fields are missing
        if (empty($normalized['full_name']) || empty($normalized['id_number']) || empty($normalized['pf_number'])) {
            return null;
        }
        
        return $normalized;
    }
    
    /**
     * Generate CSV template for user import
     */
    public static function generateTemplate() {
        $headers = ['Full Name', 'Email', 'ID Number', 'PF Number'];
        $sampleData = [
            ['John Doe', 'john.doe@example.com', '12345678', '1234567890'],
            ['Jane Smith', 'jane.smith@example.com', '87654321', '9876543210']
        ];
        
        $csv = implode(',', $headers) . "\n";
        foreach ($sampleData as $row) {
            $csv .= implode(',', array_map(function($val) {
                return '"' . str_replace('"', '""', $val) . '"';
            }, $row)) . "\n";
        }
        
        return $csv;
    }
}