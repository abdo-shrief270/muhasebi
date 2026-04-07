<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

enum NotificationType: string
{
    case Welcome = 'welcome';
    case SetupReminder = 'setup_reminder';
    case InvoiceSent = 'invoice_sent';
    case PaymentReceived = 'payment_received';
    case TrialExpiring = 'trial_expiring';
    case SubscriptionRenewed = 'subscription_renewed';
    case TeamInvite = 'team_invite';
    case SystemAlert = 'system_alert';
    case DocumentShared = 'document_shared';
    case NewMessage = 'new_message';
    case ClientInvite = 'client_invite';

    public function label(): string
    {
        return match ($this) {
            self::Welcome => 'Welcome',
            self::SetupReminder => 'Setup Reminder',
            self::InvoiceSent => 'Invoice Sent',
            self::PaymentReceived => 'Payment Received',
            self::TrialExpiring => 'Trial Expiring',
            self::SubscriptionRenewed => 'Subscription Renewed',
            self::TeamInvite => 'Team Invite',
            self::SystemAlert => 'System Alert',
            self::DocumentShared => 'Document Shared',
            self::NewMessage => 'New Message',
            self::ClientInvite => 'Client Invite',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Welcome => 'مرحباً',
            self::SetupReminder => 'تذكير بالإعداد',
            self::InvoiceSent => 'تم إرسال الفاتورة',
            self::PaymentReceived => 'تم استلام الدفعة',
            self::TrialExpiring => 'انتهاء الفترة التجريبية',
            self::SubscriptionRenewed => 'تم تجديد الاشتراك',
            self::TeamInvite => 'دعوة فريق',
            self::SystemAlert => 'تنبيه النظام',
            self::DocumentShared => 'تمت مشاركة مستند',
            self::NewMessage => 'رسالة جديدة',
            self::ClientInvite => 'دعوة عميل',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Welcome => 'bell',
            self::SetupReminder => 'settings',
            self::InvoiceSent => 'file-text',
            self::PaymentReceived => 'credit-card',
            self::TrialExpiring => 'clock',
            self::SubscriptionRenewed => 'refresh',
            self::TeamInvite => 'user-plus',
            self::SystemAlert => 'alert-triangle',
            self::DocumentShared => 'share-2',
            self::NewMessage => 'mail',
            self::ClientInvite => 'user-check',
        };
    }
}
