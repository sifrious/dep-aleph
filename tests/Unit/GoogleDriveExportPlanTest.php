<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\GoogleDrive\GoogleDriveExportPlan;

it('maps google docs to docx by default and md when preferred', function (): void {
    expect(GoogleDriveExportPlan::for(GoogleDriveExportPlan::DOCS_MIME))->toMatchArray([
        'media_type' => GoogleDriveExportPlan::DOCX,
        'extension' => 'docx',
        'export' => true,
    ])->and(GoogleDriveExportPlan::for(GoogleDriveExportPlan::DOCS_MIME, 'md'))->toMatchArray([
        'media_type' => GoogleDriveExportPlan::MARKDOWN,
        'extension' => 'md',
        'export' => true,
    ]);
});

it('maps sheets to xlsx/csv and slides to pptx/pdf', function (): void {
    expect(GoogleDriveExportPlan::for(GoogleDriveExportPlan::SHEETS_MIME))->toMatchArray([
        'media_type' => GoogleDriveExportPlan::XLSX,
        'extension' => 'xlsx',
        'export' => true,
    ])->and(GoogleDriveExportPlan::for(GoogleDriveExportPlan::SHEETS_MIME, 'csv')['extension'])->toBe('csv')
        ->and(GoogleDriveExportPlan::for(GoogleDriveExportPlan::SLIDES_MIME)['extension'])->toBe('pptx')
        ->and(GoogleDriveExportPlan::for(GoogleDriveExportPlan::SLIDES_MIME, 'pdf'))->toMatchArray([
            'media_type' => GoogleDriveExportPlan::PDF,
            'extension' => 'pdf',
            'export' => true,
        ]);
});

it('downloads ordinary binaries as-is without export', function (): void {
    expect(GoogleDriveExportPlan::for('application/pdf'))->toMatchArray([
        'media_type' => 'application/pdf',
        'extension' => 'pdf',
        'export' => false,
    ])->and(GoogleDriveExportPlan::isNativeGoogleFormat('application/pdf'))->toBeFalse()
        ->and(GoogleDriveExportPlan::isNativeGoogleFormat(GoogleDriveExportPlan::DOCS_MIME))->toBeTrue();
});
