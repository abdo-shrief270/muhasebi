<section class="relative bg-primary min-h-[500px] flex items-center justify-center overflow-hidden">
    @if($tenant->hero_image_path)
        <div class="absolute inset-0">
            <img src="{{ asset($tenant->hero_image_path) }}" alt="{{ $tenant->name }}" class="w-full h-full object-cover opacity-20">
        </div>
    @endif

    <div class="relative z-10 text-center px-6 py-20 max-w-4xl mx-auto">
        @if($tenant->logo_path)
            <img src="{{ asset($tenant->logo_path) }}" alt="{{ $tenant->name }}" class="h-20 mx-auto mb-8 rounded-lg shadow-lg bg-white p-2">
        @endif

        <h1 class="text-4xl md:text-6xl font-bold text-white mb-4">
            {{ $tenant->name }}
        </h1>

        @if($tenant->tagline)
            <p class="text-xl md:text-2xl text-white/90 mb-8">
                {{ $tenant->tagline }}
            </p>
        @endif

        <div class="flex flex-wrap gap-4 justify-center">
            <a href="#contact" class="bg-white text-primary px-8 py-3 rounded-lg font-bold text-lg hover:bg-gray-100 transition">
                {{ $locale === 'ar' ? 'تواصل معنا' : 'Contact Us' }}
            </a>
            @if($plans->count() > 0)
                <a href="#plans" class="border-2 border-white text-white px-8 py-3 rounded-lg font-bold text-lg hover:bg-white/10 transition">
                    {{ $locale === 'ar' ? 'الباقات والأسعار' : 'Plans & Pricing' }}
                </a>
            @endif
        </div>
    </div>
</section>
