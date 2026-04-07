<section id="about" class="py-20 px-6">
    <div class="max-w-4xl mx-auto text-center">
        <h2 class="text-3xl md:text-4xl font-bold text-primary mb-6">
            {{ $locale === 'ar' ? 'من نحن' : 'About Us' }}
        </h2>
        <div class="w-20 h-1 bg-secondary mx-auto mb-8 rounded"></div>
        <p class="text-lg text-gray-600 leading-relaxed whitespace-pre-line">
            {{ $tenant->description }}
        </p>
    </div>
</section>
