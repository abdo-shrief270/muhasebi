<section id="plans" class="py-20 px-6 bg-gray-50">
    <div class="max-w-6xl mx-auto">
        <h2 class="text-3xl md:text-4xl font-bold text-primary text-center mb-6">
            {{ $locale === 'ar' ? 'الباقات والأسعار' : 'Plans & Pricing' }}
        </h2>
        <div class="w-20 h-1 bg-secondary mx-auto mb-12 rounded"></div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            @foreach($plans as $plan)
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-md transition">
                    <div class="bg-primary p-6 text-center">
                        <h3 class="text-xl font-bold text-white">{{ $plan['name'] }}</h3>
                    </div>
                    <div class="p-8">
                        <div class="text-center mb-6">
                            <span class="text-4xl font-bold text-primary">{{ number_format((float) $plan['price_monthly'], 0) }}</span>
                            <span class="text-gray-500 text-sm">
                                {{ $locale === 'ar' ? 'ج.م. / شهرياً' : 'EGP / month' }}
                            </span>
                        </div>

                        @if($plan['description'])
                            <p class="text-gray-600 text-sm text-center mb-6">{{ $plan['description'] }}</p>
                        @endif

                        @if(is_array($plan['features']) && count($plan['features']) > 0)
                            <ul class="space-y-3 mb-8">
                                @foreach($plan['features'] as $feature => $value)
                                    @if($value)
                                        <li class="flex items-center gap-2 text-sm text-gray-700">
                                            <span class="text-green-500 font-bold">&#10003;</span>
                                            {{ is_string($feature) ? $feature : $value }}
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
