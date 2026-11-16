<?php
/**
 * Inisialisasi bersama untuk halaman submenu WhatsApp Gateway.
 *
 * Semua variabel dinormalisasi di sini agar setiap view submenu tetap mandiri
 * dan aman ketika data opsional tidak dikirim controller.
 */

declare(strict_types=1);

$gateway = is_array($gateway ?? null) ? $gateway : [];
$otp = is_array($otp ?? null) ? $otp : [];
$notification = is_array($notification ?? null) ? $notification : [];
$report = is_array($report ?? null) ? $report : [];
$gs = is_array($gateway['settings'] ?? null) ? $gateway['settings'] : [];
$os = is_array($otp['settings'] ?? null) ? $otp['settings'] : [];
$ns = is_array($notification['settings'] ?? null) ? $notification['settings'] : [];
$rs = is_array($report['settings'] ?? null) ? $report['settings'] : [];
$connections = is_array($connections ?? null) ? $connections : [];
$editConnection = is_array($editConnection ?? null) ? $editConnection : null;
$options = is_array($connectionOptions ?? null) ? $connectionOptions : ['' => 'Otomatis'];
$providers = is_array($providerOptions ?? null) ? $providerOptions : [];
$otpStats = is_array($otpMonitoring ?? null) ? $otpMonitoring : [];
$notifStats = is_array($notificationMonitoring ?? null) ? $notificationMonitoring : [];
$events = is_array($ns['events'] ?? null) ? $ns['events'] : [];
$eventMeta = is_array($notificationEvents ?? null) ? $notificationEvents : [];
$recipientOptions = is_array($recipientOptions ?? null) ? $recipientOptions : [];
$audits = is_array($audits ?? null) ? $audits : [];
$webhookUrls = is_array($webhookUrls ?? null) ? $webhookUrls : [];
$placeholders = is_array($placeholders ?? null) ? $placeholders : [];
$reportTypes = is_array($reportTypes ?? null) ? $reportTypes : [];
$reportConnections = is_array($reportConnections ?? null) ? $reportConnections : [];
$reportRecipients = is_array($reportRecipients ?? null) ? $reportRecipients : [];
$reportFilters = is_array($reportFilters ?? null) ? $reportFilters : [];
$reportRentangLabels = is_array($reportRentangLabels ?? null) ? $reportRentangLabels : [];
$reportPreview = is_array($reportPreview ?? null) ? $reportPreview : null;
$reportPreviewError = (string) ($reportPreviewError ?? '');
$reportHistory = is_array($reportHistory ?? null) ? $reportHistory : [];
$reportLast = is_array($reportLast ?? null) ? $reportLast : null;
$reportUsage = is_array($reportUsage ?? null) ? $reportUsage : [];
$reportGatewayReady = !empty($reportGatewayReady);
$gatewayUsage = is_array($gatewayUsage ?? null) ? $gatewayUsage : [];

$flashSukses = $flash_sukses ?? null;
$flashError = $flash_error ?? null;
$pageTitle = (string) ($pageTitle ?? 'WhatsApp Gateway');
$pageSubtitle = (string) ($pageSubtitle ?? '');

$credentialAction = url('/admin/whatsapp/kredensial');
$otpProviderLabel = (string) ($otpProviderLabel ?? 'Belum dipilih');
$otpProviderSupportsTemplates = !empty($otpProviderSupportsTemplates);
$selectedProvider = (string) ($editConnection['provider'] ?? 'meta_cloud');
$isEdit = $editConnection !== null;
$eventList = is_array($eventMeta) ? $eventMeta : [];

$formatNumber = static fn (mixed $value): string => number_format((int) $value, 0, ',', '.');
$checked = static fn (mixed $value): string => !empty($value) ? ' checked' : '';
$selected = static fn (mixed $value, mixed $expected): string => (string) $value === (string) $expected ? ' selected' : '';
