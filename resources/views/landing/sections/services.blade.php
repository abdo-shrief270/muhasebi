<section id="services" class="py-20 px-6 bg-gray-50">
    <div class="max-w-6xl mx-auto">
        <h2 class="text-3xl md:text-4xl font-bold text-primary text-center mb-6">
            {{ $locale === 'ar' ? 'خدماتنا' : 'Our Services' }}
        </h2>
        <div class="w-20 h-1 bg-secondary mx-auto mb-12 rounded"></div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            @foreach($services as $service)
                <div class="bg-white rounded-xl shadow-sm p-8 hover:shadow-md transition border border-gray-100">
                    @if(isset($service['icon']))
                        <div class="text-secondary text-3xl mb-4">{{ $service['icon'] }}</div>
                    @endif
                    <h3 class="text-xl font-bold text-primary mb-3">
                        {{ $service['title'] ?? '' }}
                    </h3>
                    <p class="text-gray-600 leading-relaxed">
                        {{ $service['description'] ?? '' }}
                    </p>
                </div>
            @endforeach
        </div>
    </div>
</section>
