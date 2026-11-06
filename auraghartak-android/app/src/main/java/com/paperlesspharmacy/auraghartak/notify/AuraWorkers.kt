package com.paperlesspharmacy.auraghartak.notify

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.paperlesspharmacy.auraghartak.data.EngagementPrefs
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.concurrent.TimeUnit

object AuraWorkScheduler {
    private const val ORDER_POLL = "agt_order_poll"
    private const val ORDER_POLL_NOW = "agt_order_poll_now"
    private const val REFILL_CHECK = "agt_refill_check"

    fun schedule(context: Context) {
        val wm = WorkManager.getInstance(context)
        wm.enqueueUniquePeriodicWork(
            ORDER_POLL,
            ExistingPeriodicWorkPolicy.UPDATE,
            PeriodicWorkRequestBuilder<OrderStatusWorker>(15, TimeUnit.MINUTES).build(), // Android minimum interval
        )
        wm.enqueueUniquePeriodicWork(
            REFILL_CHECK,
            ExistingPeriodicWorkPolicy.KEEP,
            PeriodicWorkRequestBuilder<RefillReminderWorker>(12, TimeUnit.HOURS).build(),
        )
        runOrderSyncNow(context)
    }

    fun runOrderSyncNow(context: Context) {
        val req = OneTimeWorkRequestBuilder<OrderStatusWorker>().build()
        WorkManager.getInstance(context).enqueueUniqueWork(
            ORDER_POLL_NOW,
            ExistingWorkPolicy.REPLACE,
            req,
        )
    }
}

class OrderStatusWorker(appContext: Context, params: WorkerParameters) : CoroutineWorker(appContext, params) {
    override suspend fun doWork(): Result {
        runCatching { OrderStatusSync.pollAllSavedOrders(applicationContext) }
        return Result.success()
    }
}

class RefillReminderWorker(appContext: Context, params: WorkerParameters) : CoroutineWorker(appContext, params) {
    override suspend fun doWork(): Result {
        val engagement = EngagementPrefs(applicationContext)
        if (!engagement.notificationsEnabled()) return Result.success()
        val today = LocalDate.now()
        val fmt = DateTimeFormatter.ofPattern("dd-MM-yyyy")
        for (refill in engagement.refills()) {
            runCatching {
                val due = LocalDate.parse(refill.nextDue, fmt)
                if (!due.isAfter(today)) {
                    val label = if (due.isBefore(today)) "overdue" else "due today"
                    NotificationHelper.notifyRefill(applicationContext, refill.medicineName, label)
                }
            }
        }
        return Result.success()
    }
}
