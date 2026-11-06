package com.paperlesspharmacy.auraghartak.data

import android.content.Context
import android.content.Intent
import android.net.Uri

fun openWhatsAppChat(context: Context, phoneRaw: String, message: String) {
    val digits = phoneRaw.filter { it.isDigit() }.takeLast(10)
    if (digits.length < 10) {
        sharePlainText(context, message)
        return
    }
    val waPhone = "91$digits"
    val text = Uri.encode(message)
    val uri = Uri.parse("https://wa.me/$waPhone?text=$text")
    val intent = Intent(Intent.ACTION_VIEW, uri).apply {
        setPackage("com.whatsapp")
        addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
    }
    if (intent.resolveActivity(context.packageManager) != null) {
        context.startActivity(intent)
    } else {
        context.startActivity(Intent(Intent.ACTION_VIEW, uri).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }
}

fun sharePlainText(context: Context, message: String) {
    val send = Intent(Intent.ACTION_SEND).apply {
        type = "text/plain"
        putExtra(Intent.EXTRA_TEXT, message)
        addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
    }
    context.startActivity(Intent.createChooser(send, "Send order").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
}
