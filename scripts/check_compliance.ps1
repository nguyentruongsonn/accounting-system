# Script Tự Động Kiểm Tra Tuân Thủ Quy Tắc Code (Frontend & Backend)
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "   ACCOUNTING2 - AUTOMATED CODE COMPLIANCE CHECKER        " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

$frontendPath = "frontend\src"
$backendPath = "backend\app"

# 1. Check Inline CSS
$inlineStyles = Get-ChildItem -Path "$frontendPath\features" -Recurse -Include "*.tsx" | Select-String -Pattern 'style=\{\{'
$inlineCount = ($inlineStyles | Measure-Object).Count

# 2. Check Hardcoded Colors
$badColors = Get-ChildItem -Path $frontendPath -Recurse -Include "*.tsx" | Select-String -Pattern '#00a86b|#22c55e|#2ca01c|#cbd5e1'
$badColorsCount = ($badColors | Measure-Object).Count

# 3. Check Files > 500 lines
$longFiles = Get-ChildItem -Path "$frontendPath\features" -Recurse -Include "*.tsx" | ForEach-Object {
    $lines = (Get-Content $_.FullName | Measure-Object -Line).Lines
    if ($lines -gt 500) {
        [PSCustomObject]@{ File = $_.Name; Lines = $lines; Path = $_.FullName }
    }
}

# 4. Check CSS files
$cssFiles = Get-ChildItem -Path $frontendPath -Recurse -Include "*.css"

# 5. Check Backend direct response()->json
$rawJson = Get-ChildItem -Path "$backendPath\Http\Controllers" -Recurse -Include "*.php" | Select-String -Pattern 'response\(\)->json'
$rawJsonCount = ($rawJson | Measure-Object).Count

# 6. Check Backend Services > 300 lines
$longServices = Get-ChildItem -Path "$backendPath\Services" -Include "*.php" -Recurse | ForEach-Object {
    $lines = (Get-Content $_.FullName | Measure-Object -Line).Lines
    if ($lines -gt 300) {
        [PSCustomObject]@{ Service = $_.Name; Lines = $lines }
    }
}

Write-Host "`n--- [FRONTEND COMPLIANCE] ---" -ForegroundColor Yellow
if ($inlineCount -eq 0) {
    Write-Host "[PASS] Zero inline styles" -ForegroundColor Green
} else {
    Write-Host "[FAIL] Found $inlineCount inline style occurrences (style={{...}})" -ForegroundColor Red
}

if ($badColorsCount -eq 0) {
    Write-Host "[PASS] Zero non-standard hardcoded colors" -ForegroundColor Green
} else {
    Write-Host "[FAIL] Found $badColorsCount non-standard color occurrences" -ForegroundColor Red
}

if (($longFiles | Measure-Object).Count -eq 0) {
    Write-Host "[PASS] All TSX feature files <= 500 lines" -ForegroundColor Green
} else {
    Write-Host "[FAIL] Found $(($longFiles | Measure-Object).Count) files exceeding 500 lines:" -ForegroundColor Red
    $longFiles | Format-Table File, Lines -AutoSize
}

Write-Host "`n--- [BACKEND COMPLIANCE] ---" -ForegroundColor Yellow
if ($rawJsonCount -eq 0) {
    Write-Host "[PASS] All responses wrapped in API Resources" -ForegroundColor Green
} else {
    Write-Host "[FAIL] Found $rawJsonCount direct response()->json() calls without API Resources" -ForegroundColor Red
}

if (($longServices | Measure-Object).Count -eq 0) {
    Write-Host "[PASS] All Services <= 300 lines" -ForegroundColor Green
} else {
    Write-Host "[WARN] Found $(($longServices | Measure-Object).Count) Services exceeding 300 lines" -ForegroundColor Yellow
}

Write-Host "`n==========================================================" -ForegroundColor Cyan
