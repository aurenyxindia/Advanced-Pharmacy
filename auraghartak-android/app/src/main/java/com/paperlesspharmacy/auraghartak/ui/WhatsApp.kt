package com.paperlesspharmacy.auraghartak.ui

import android.content.Context
import com.paperlesspharmacy.auraghartak.data.openWhatsAppChat as openWhatsAppChatData

fun openWhatsAppChat(context: Context, phoneRaw: String, message: String) =
    openWhatsAppChatData(context, phoneRaw, message)
