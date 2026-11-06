package com.paperlesspharmacy.auraghartak.notify

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.paperlesspharmacy.auraghartak.MainActivity
import com.paperlesspharmacy.auraghartak.R

object NotificationHelper {
    /** v2 = high importance + default notification sound (channels are immutable after create). */
    const val CHANNEL_ORDERS = "agt_order_updates_v2"
    const val CHANNEL_REFILLS = "agt_refills_v2"

    fun ensureChannels(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val mgr = context.getSystemService(NotificationManager::class.java) ?: return
        val sound: Uri = Settings.System.DEFAULT_NOTIFICATION_URI
        val audio = AudioAttributes.Builder()
            .setUsage(AudioAttributes.USAGE_NOTIFICATION)
            .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
            .build()
        mgr.createNotificationChannel(
            NotificationChannel(CHANNEL_ORDERS, "Order updates", NotificationManager.IMPORTANCE_HIGH).apply {
                description = "Delivery and order status from your pharmacy"
                enableVibration(true)
                vibrationPattern = longArrayOf(0, 120, 80, 120)
                setSound(sound, audio)
                lockscreenVisibility = NotificationCompat.VISIBILITY_PUBLIC
            },
        )
        mgr.createNotificationChannel(
            NotificationChannel(CHANNEL_REFILLS, "Refill reminders", NotificationManager.IMPORTANCE_DEFAULT).apply {
                description = "Medicine refill reminders you set"
                setSound(sound, audio)
            },
        )
    }

    fun notifyOrderStatus(context: Context, orderNo: String, status: String) {
        ensureChannels(context)
        val intent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra(MainActivity.EXTRA_OPEN_ORDERS, true)
        }
        val pending = PendingIntent.getActivity(
            context,
            orderNo.hashCode(),
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val norm = OrderStatusSync.normalizeStatus(status)
        val pharmacyLine = when (norm) {
            "ready" -> "Your medicines are ready"
            "confirmed" -> "Your order is confirmed"
            "packed" -> "Your order is packed"
            "out_for_delivery" -> "Your order is on the way"
            "delivered" -> "Your order has been delivered"
            "cancelled", "canceled" -> "Your order was cancelled"
            else -> "Your order status was updated"
        }
        val title = when (norm) {
            "ready" -> "Order ready"
            "out_for_delivery" -> "Out for delivery"
            "delivered" -> "Delivered"
            "cancelled", "canceled" -> "Order cancelled"
            else -> "AuraGharTak · Order update"
        }
        val displayStatus = status.trim().ifBlank { status }
            .replace('_', ' ')
        val body = "$pharmacyLine\nRef $orderNo · $displayStatus"
        val notification = NotificationCompat.Builder(context, CHANNEL_ORDERS)
            .setSmallIcon(R.drawable.ic_launcher_foreground)
            .setContentTitle(title)
            .setContentText(pharmacyLine)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setContentIntent(pending)
            .setAutoCancel(true)
            .setOnlyAlertOnce(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_STATUS)
            .setDefaults(NotificationCompat.DEFAULT_ALL)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
            .build()
        runCatching {
            NotificationManagerCompat.from(context).notify(orderNo.hashCode(), notification)
        }
    }

    fun notifyRefill(context: Context, medicineName: String, dueLabel: String) {
        ensureChannels(context)
        val intent = Intent(context, MainActivity::class.java)
        val pending = PendingIntent.getActivity(
            context,
            medicineName.hashCode(),
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val notification = NotificationCompat.Builder(context, CHANNEL_REFILLS)
            .setSmallIcon(R.drawable.ic_launcher_foreground)
            .setContentTitle("Refill reminder")
            .setContentText("$medicineName — $dueLabel")
            .setContentIntent(pending)
            .setAutoCancel(true)
            .build()
        runCatching {
            NotificationManagerCompat.from(context).notify(medicineName.hashCode(), notification)
        }
    }

    fun notifyStockBack(context: Context, productName: String, pharmacyName: String) {
        ensureChannels(context)
        val intent = Intent(context, MainActivity::class.java)
        val pending = PendingIntent.getActivity(
            context,
            productName.hashCode(),
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val notification = NotificationCompat.Builder(context, CHANNEL_ORDERS)
            .setSmallIcon(R.drawable.ic_launcher_foreground)
            .setContentTitle("Back in stock")
            .setContentText("$productName at $pharmacyName")
            .setContentIntent(pending)
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .build()
        runCatching {
            NotificationManagerCompat.from(context).notify(productName.hashCode(), notification)
        }
    }
}
