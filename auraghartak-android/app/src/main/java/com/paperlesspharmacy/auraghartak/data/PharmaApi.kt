package com.paperlesspharmacy.auraghartak.data

import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.paperlesspharmacy.auraghartak.BuildConfig
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException
import java.net.URLEncoder
import java.util.concurrent.TimeUnit

/**
 * Customer calls only. These routes already exist on the Paperless Pharmacy API.
 * Store orders:  /api/public/branches, /catalog, /orders, /orders/track, /orders/cancel
 * Share-link shop: /api/public/agt/shop, /api/public/agt/orders
 */
class PharmaApi {
    private val base = BuildConfig.API_BASE.trimEnd('/')
    private val json = "application/json; charset=utf-8".toMediaType()
    private val client = OkHttpClient.Builder()
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .addInterceptor { chain ->
            val builder = chain.request().newBuilder()
                .header("Accept", "application/json")
                .header("User-Agent", "AuraGharTak-Android/1.0")
            val token = CustomerSession.token()
            if (token.isNotBlank() && chain.request().header("Authorization") == null) {
                builder.header("Authorization", "Bearer $token")
            }
            chain.proceed(builder.build())
        }
        .build()

    fun branches(): List<Branch> {
        val data = call(Request.Builder().url("$base/public/branches").get()).arr("data")
        return data.mapNotNull { el ->
            val o = el.asJsonObjectOrNull() ?: return@mapNotNull null
            val id = o.lng("id")
            if (id <= 0L) null else Branch(
                id = id,
                name = o.str("name"),
                city = o.str("city"),
                businessName = o.str("business_name"),
                state = o.str("state"),
                pincode = o.str("pincode"),
                address = o.str("address"),
                phone = o.str("phone"),
            )
        }
    }

    fun catalogSubstitutes(branchId: Long, bindingId: Long): List<SubstituteItem> {
        val url = "$base/public/catalog/substitutes?branch_id=$branchId&binding_id=$bindingId"
        val data = call(Request.Builder().url(url).get()).arr("data")
        return data.mapNotNull { el ->
            val o = el.asJsonObjectOrNull() ?: return@mapNotNull null
            val id = o.lng("binding_id")
            if (id <= 0L) null else SubstituteItem(
                bindingId = id,
                productName = o.str("product_name"),
                scheduleCategory = o.str("schedule_category"),
                availableQty = o.dbl("available_qty"),
                mrp = o.dbl("mrp"),
                genericName = o.str("generic_name"),
                unitsPerPack = o.dbl("units_per_pack").toInt().coerceAtLeast(1),
                packSize = o.str("pack_size"),
            )
        }
    }

    fun storeLocations(): List<StoreLocation> {
        val data = call(Request.Builder().url("$base/public/store-locations").get()).obj("data")
        return data.arr("locations").mapNotNull { el ->
            val o = el.asJsonObjectOrNull() ?: return@mapNotNull null
            val lat = o.dbl("latitude")
            val lng = o.dbl("longitude")
            if (!Geo.isValidCoord(lat, lng)) return@mapNotNull null
            StoreLocation(
                source = o.str("source").ifBlank { "advanced" },
                storeKey = o.str("store_key"),
                latitude = lat,
                longitude = lng,
            )
        }.filter { it.storeKey.isNotBlank() }
    }

    fun syncRetailLocationStubs(clientNos: List<String>) {
        val codes = clientNos.map { it.trim() }.filter { it.isNotEmpty() }.distinct().take(120)
        if (codes.isEmpty()) return
        val stores = JsonArray()
        codes.forEach { code ->
            stores.add(JsonObject().apply {
                addProperty("source", "retail")
                addProperty("store_key", code)
            })
        }
        val body = JsonObject().apply { add("stores", stores) }
        call(
            Request.Builder()
                .url("$base/public/store-locations/stubs")
                .post(body.toString().toRequestBody(json)),
        )
    }

    fun searchProductNames(branchId: Long, query: String, limit: Int = 8): List<String> {
        val q = query.trim()
        if (q.length < 2) return emptyList()
        val cap = limit.coerceIn(1, 20)
        return runCatching { catalog(branchId, q) }
            .getOrDefault(emptyList())
            .filter { ProductSearchMatch.matchesQuery(q, it.productName, it.scheduleCategory) }
            .take(cap)
            .map { it.productName }
            .filter { it.isNotBlank() }
            .distinct()
    }

    fun catalog(branchId: Long, query: String): List<CatalogItem> {
        val url = "$base/public/catalog?branch_id=$branchId&q=${enc(query)}"
        val data = call(Request.Builder().url(url).get()).arr("data")
        return data.mapNotNull { el ->
            val o = el.asJsonObjectOrNull() ?: return@mapNotNull null
            val id = o.lng("binding_id")
            val name = o.str("product_name")
            if (id <= 0L || name.isBlank()) null else CatalogItem(
                bindingId = id,
                productName = name,
                scheduleCategory = o.str("schedule_category"),
                availableQty = o.dbl("available_qty"),
                mrp = o.dbl("mrp"),
                unitsPerPack = o.dbl("units_per_pack").toInt().coerceAtLeast(1),
                packSize = o.str("pack_size"),
            )
        }
    }

    fun placeOrder(
        branchId: Long,
        profile: Profile,
        note: String,
        items: List<CartLine>,
        deliverySlot: String = "",
        prescriptionBase64: String? = null,
        paymentMethod: String = "",
        drop: Pair<Double, Double>? = null,
    ): PlaceResult {
        val body = JsonObject().apply {
            addProperty("branch_id", branchId)
            drop?.let {
                addProperty("drop_latitude", it.first)
                addProperty("drop_longitude", it.second)
            }
            addProperty("customer_name", profile.name.trim())
            addProperty("phone", profile.phone.trim())
            addProperty("address", profile.address.trim())
            addProperty("pincode", profile.pincode.trim())
            addProperty("notes", note.trim())
            if (paymentMethod.isNotBlank()) addProperty("payment_method", paymentMethod.trim())
            if (deliverySlot.isNotBlank()) addProperty("delivery_slot", deliverySlot.trim())
            if (!prescriptionBase64.isNullOrBlank()) addProperty("prescription_base64", prescriptionBase64)
            val arr = JsonArray()
            items.forEach { line ->
                arr.add(JsonObject().apply {
                    addProperty("product_binding_id", line.bindingId)
                    addProperty("product_name", line.name)
                    addProperty("quantity", line.qty)
                    addProperty("rate", line.rate)
                })
            }
            add("items", arr)
        }
        val data = call(
            Request.Builder()
                .url("$base/public/orders")
                .post(body.toString().toRequestBody(json))
        ).obj("data")
        return PlaceResult(
            orderNo = data.str("order_no"),
            trackingToken = data.str("tracking_token"),
            total = data.dbl("total_estimate"),
        )
    }

    fun track(phone: String = "", orderNo: String = "", token: String = ""): TrackResult {
        val parts = mutableListOf<String>()
        if (token.isNotBlank()) parts += "token=${enc(token)}"
        if (orderNo.isNotBlank()) parts += "order_no=${enc(orderNo)}"
        if (phone.isNotBlank()) parts += "phone=${enc(phone)}"
        val root = call(Request.Builder().url("$base/public/orders/track?${parts.joinToString("&")}").get())
        val data = root.obj("data")
        return if (data.str("mode") == "list") {
            TrackResult.Many(data.arr("orders").mapNotNull { it.asJsonObjectOrNull()?.toSummary() })
        } else {
            TrackResult.One(data.toDetail())
        }
    }

    fun cancel(token: String = "", orderNo: String = "", phone: String = "") {
        val body = JsonObject().apply {
            if (token.isNotBlank()) addProperty("token", token)
            if (orderNo.isNotBlank()) addProperty("order_no", orderNo)
            if (phone.isNotBlank()) addProperty("phone", phone)
        }
        call(Request.Builder().url("$base/public/orders/cancel").post(body.toString().toRequestBody(json)))
    }

    fun agtShop(token: String, query: String): AgtShop {
        val url = "$base/public/agt/shop?token=${enc(token)}&q=${enc(query)}"
        val data = call(Request.Builder().url(url).get()).obj("data")
        val pharmacy = data.obj("pharmacy")
        val patient = data.obj("patient")
        val products = data.arr("products").mapNotNull { el ->
            val o = el.asJsonObjectOrNull() ?: return@mapNotNull null
            val name = o.str("p_name")
            if (name.isBlank() || o.dbl("availableQty") <= 0 || ExpiryCheck.isExpired(o.str("expiry_date"))) null else AgtProduct(
                name = name,
                batchNo = o.str("batch_no"),
                packSize = o.str("pack_size"),
                category = o.str("p_cat"),
                unitMrp = o.dbl("unit_mrp"),
                availableQty = o.dbl("availableQty"),
                expiry = o.str("expiry_date"),
            )
        }
        return AgtShop(
            token = data.str("token").ifBlank { token },
            pharmacyName = pharmacy.str("name"),
            pharmacyContact = pharmacy.str("contact"),
            pharmacyAddress = pharmacy.str("address"),
            patientName = patient.str("name"),
            patientContact = patient.str("contact"),
            patientAddress = patient.str("address"),
            products = products,
        )
    }

    fun placeAgt(
        token: String,
        items: List<AgtCartLine>,
        note: String,
        prescription: String?,
        paymentMethod: String = "",
        drop: Pair<Double, Double>? = null,
    ): AgtPlaceResult {
        val body = JsonObject().apply {
            addProperty("token", token)
            drop?.let {
                addProperty("drop_latitude", it.first)
                addProperty("drop_longitude", it.second)
            }
            addProperty("patient_note", note.trim())
            if (paymentMethod.isNotBlank()) addProperty("payment_method", paymentMethod.trim())
            if (!prescription.isNullOrBlank()) addProperty("prescription_base64", prescription)
            val arr = JsonArray()
            items.forEach { line ->
                arr.add(JsonObject().apply {
                    addProperty("p_name", line.name)
                    addProperty("batch_no", line.batchNo)
                    addProperty("qty", line.qty)
                    addProperty("unit_mrp", line.unitMrp)
                })
            }
            add("items", arr)
        }
        val data = call(
            Request.Builder()
                .url("$base/public/agt/orders")
                .post(body.toString().toRequestBody(json))
        ).obj("data")
        return AgtPlaceResult(orderNo = data.str("order_no"), total = data.dbl("estimated_total"))
    }

    private fun call(builder: Request.Builder): JsonObject {
        val responseText = try {
            client.newCall(builder.build()).execute().use { response ->
                val text = response.body?.string().orEmpty()
                if (text.isBlank()) {
                    throw ApiException(
                        if (response.isSuccessful) "Empty response from the server."
                        else "The pharmacy server could not complete that request."
                    )
                }
                text
            }
        } catch (e: ApiException) {
            throw e
        } catch (_: IOException) {
            throw ApiException("No internet connection. Check your network and try again.")
        }

        val root = try {
            JsonParser.parseString(responseText).asJsonObject
        } catch (_: Exception) {
            throw ApiException("Unexpected response from the pharmacy server.")
        }
        val ok = root.get("success")?.takeIf { !it.isJsonNull }?.asBoolean == true
        if (!ok) throw ApiException(messageOf(root))
        return root
    }

    private fun messageOf(root: JsonObject): String {
        val errors = root.get("errors")?.takeIf { it.isJsonObject }?.asJsonObject
        if (errors != null) {
            for (entry in errors.entrySet()) {
                val value = entry.value
                if (value != null && value.isJsonArray && value.asJsonArray.size() > 0) {
                    val first = value.asJsonArray[0]
                    if (first != null && first.isJsonPrimitive) {
                        val text = first.asString
                        if (text.isNotBlank()) return text
                    }
                }
            }
        }
        val message = root.str("message")
        return message.ifBlank { "Something went wrong. Please try again." }
    }

    private fun enc(value: String): String = URLEncoder.encode(value, Charsets.UTF_8.name())
}

private fun JsonObject.str(key: String): String {
    val el = get(key) ?: return ""
    if (el.isJsonNull || !el.isJsonPrimitive) return ""
    return el.asString.trim()
}

private fun JsonObject.dbl(key: String): Double {
    val el = get(key) ?: return 0.0
    if (el.isJsonNull || !el.isJsonPrimitive) return 0.0
    val p = el.asJsonPrimitive
    return if (p.isNumber) p.asDouble else p.asString.toDoubleOrNull() ?: 0.0
}

private fun JsonObject.int(key: String): Int {
    val el = get(key) ?: return 0
    if (el.isJsonNull || !el.isJsonPrimitive) return 0
    val p = el.asJsonPrimitive
    return if (p.isNumber) p.asInt else p.asString.toIntOrNull() ?: 0
}

private fun JsonObject.lng(key: String): Long {
    val el = get(key) ?: return 0L
    if (el.isJsonNull || !el.isJsonPrimitive) return 0L
    val p = el.asJsonPrimitive
    return if (p.isNumber) p.asLong else p.asString.toLongOrNull() ?: 0L
}

private fun JsonObject.bool(key: String): Boolean {
    val el = get(key) ?: return false
    if (el.isJsonNull || !el.isJsonPrimitive) return false
    val p = el.asJsonPrimitive
    return when {
        p.isBoolean -> p.asBoolean
        p.isNumber -> p.asInt != 0
        else -> p.asString == "1" || p.asString.equals("true", true)
    }
}

private fun JsonObject.obj(key: String): JsonObject {
    val el = get(key)
    return if (el != null && el.isJsonObject) el.asJsonObject else JsonObject()
}

private fun JsonObject.arr(key: String): List<com.google.gson.JsonElement> {
    val el = get(key)
    if (el == null || !el.isJsonArray) return emptyList()
    return el.asJsonArray.toList()
}

private fun com.google.gson.JsonElement.asJsonObjectOrNull(): JsonObject? =
    if (isJsonObject) asJsonObject else null

private fun JsonObject.toSummary(): OrderSummary = OrderSummary(
    orderNo = str("order_no"),
    customerName = str("customer_name"),
    status = str("status"),
    total = dbl("total_estimate"),
    createdAt = str("created_at"),
    orderKind = str("order_kind").ifBlank { "store" },
)

private fun JsonObject.toDetail(): OrderDetail {
    val events = arr("events").mapNotNull { el ->
        val o = el.asJsonObjectOrNull() ?: return@mapNotNull null
        OrderEvent(status = o.str("status"), note = o.str("note"), createdAt = o.str("created_at"))
    }
    val lines = arr("items").mapNotNull { el ->
        val o = el.asJsonObjectOrNull() ?: return@mapNotNull null
        val name = o.str("name").ifBlank { o.str("product_name").ifBlank { o.str("p_name") } }
        if (name.isBlank()) return@mapNotNull null
        SavedOrderLine(
            bindingId = o.lng("binding_id"),
            name = name,
            qty = o.int("qty").coerceAtLeast(1),
            rate = o.dbl("rate").let { if (it > 0) it else o.dbl("unit_mrp") },
        )
    }
    return OrderDetail(
        orderNo = str("order_no"),
        customerName = str("customer_name"),
        phone = str("phone"),
        status = str("status"),
        total = dbl("total_estimate"),
        createdAt = str("created_at"),
        cancellable = bool("cancellable"),
        events = events,
        address = str("address"),
        lines = lines,
    )
}
