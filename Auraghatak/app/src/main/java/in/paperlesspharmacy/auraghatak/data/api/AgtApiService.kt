package in.paperlesspharmacy.auraghatak.data.api

import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Query

interface AgtApiService {
    @GET("public/agt/shop")
    suspend fun shop(
        @Query("token") token: String,
        @Query("q") query: String = "",
    ): ApiEnvelope<ShopPayload>

    @POST("public/agt/orders")
    suspend fun placeOrder(@Body body: PlaceOrderRequest): ApiEnvelope<PlaceOrderResult>

    @GET("public/branches")
    suspend fun branches(): ApiEnvelope<List<PublicBranch>>
}
