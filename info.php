<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Om Værvakt</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>body { background-color: #0b0f1a; color: white; margin-bottom: 90px; }</style>
</head>
<body class="p-8 max-w-xl mx-auto">
    <div class="space-y-8 text-center">
        <h2 class="text-3xl font-black text-sky-400 italic uppercase">Vår Visjon</h2>
        <p class="text-slate-400 text-sm leading-relaxed font-medium">
            Værvakt er en uavhengig tjeneste som kombinerer data fra MET med sanntidsrapporter fra folk som faktisk er ute i været.
        </p>
        
        <div class="grid gap-4 text-left">
            <div class="bg-slate-800/30 p-6 rounded-3xl border border-white/5">
                <h3 class="font-bold text-sky-400 flex items-center gap-2">
                    <i data-lucide="database" class="w-4 h-4"></i> Uavhengig Data
                </h3>
                <p class="text-[11px] text-slate-400 mt-2 font-medium">Vi stoler på våre egne sensorer og observatører der modellene til de store aktørene blir for unøyaktige.</p>
            </div>
            <div class="bg-slate-800/30 p-6 rounded-3xl border border-white/5">
                <h3 class="font-bold text-sky-400 flex items-center gap-2">
                    <i data-lucide="heart" class="w-4 h-4"></i> Drevet av folket
                </h3>
                <p class="text-[11px] text-slate-400 mt-2 font-medium">Hver eneste rapport teller og bidrar til at naboen din vet nøyaktig hva slags vær som venter.</p>
            </div>
        </div>
    </div>

    <nav class="fixed bottom-0 left-0 right-0 bg-slate-900/90 backdrop-blur-xl border-t border-white/5 px-10 py-6 z-50">
        <div class="flex justify-between items-center max-w-md mx-auto">
            <a href="index.php" class="text-slate-400 flex flex-col items-center">
                <i data-lucide="layout-dashboard"></i>
                <span class="text-[9px] mt-1.5 font-black uppercase tracking-tighter">Oversikt</span>
            </a>
            <a href="extra.php" class="text-slate-400 flex flex-col items-center">
                <i data-lucide="waves"></i>
                <span class="text-[9px] mt-1.5 font-black uppercase tracking-tighter">Vann/Snø</span>
            </a>
            <a href="info.php" class="text-sky-400 flex flex-col items-center">
                <i data-lucide="shield-check"></i>
                <span class="text-[9px] mt-1.5 font-black uppercase tracking-tighter">Om oss</span>
            </a>
        </div>
    </nav>
    <script>lucide.createIcons();</script>
</body>
</html>