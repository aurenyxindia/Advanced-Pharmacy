package com.paperlesspharmacy.auraghartak.data

import android.content.Context

/**
 * Logged-in customer token for API clients built without a Context (PharmaApi, RetailApi).
 * The server only returns order details, phone order lists and the live map to the order's own customer.
 */
object CustomerSession {
    @Volatile
    private var appContext: Context? = null

    fun init(context: Context) {
        appContext = context.applicationContext
    }

    fun token(): String = appContext?.let { UserPrefs(it).authToken() }.orEmpty()
}
