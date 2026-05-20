<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vann & Snø | Værvakt</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>body { background-color: #0b0f1a; color: white; margin-bottom: 90px; }</style>
</head>
<body class="p-8 max-w-xl mx-auto">
    <header class="mb-8">
        <h2 class="text-2xl font-black text-sky-400 italic uppercase tracking-tighter leading-none">Spesial- <br><span class="text-white">observasjoner</span></h2>
    </header>
    
    <div class="space-y-4">
        <div class="bg-slate-800/50 p-6 rounded-[2rem] border border-white/5 flex justify-between items-center">
            <div>
                <h3 class="font-bold flex items-center gap-2 text-blue-400"><i data-lucide="thermometer-snowflake"></i> Badetemperatur</h3>
                <p class="text-[10px] text-slate-500 uppercase font-bold tracking-widest mt-1">Siste fra Bystranda</p>
            </div>
            <span class="text-3xl font-black italic">14°</span>
        </div>

        <div class="bg-slate-800/50 p-6 rounded-[2rem] border border-white/5 flex justify-between items-center">
            <div>
                <h3 class="font-bold flex items-center gap-2 text-sky-100"><i data-lucide="snowflake"></i> Snødybde</h3>
                <p class="text-[10px] text-slate-500 uppercase font-bold tracking-widest mt-1">Lokal måling</p>
            </div>
            <span class="text-3xl font-black italic text-slate-600">0 cm</span>
        </div>
    </div>

    <nav class="fixed bottom-0 left-0 right-0 bg-slate-900/90 backdrop-blur-xl border-t border-white/5 px-10 py-6 z-50">
        <div class="flex justify-between items-center max-w-md mx-auto">
            <a href="index.php" class="text-slate-400 flex flex-col items-center">
                <i data-lucide="layout-dashboard"></i>
                <span class="text-[9px] mt-1.5 font-black uppercase tracking-tighter">Oversikt</span>
            </a>
            <a href="extra.php" class="text-sky-400 flex flex-col items-center">
                <i data-lucide="waves"></i>
                <span class="text-[9px] mt-1.5 font-black uppercase tracking-tighter">Vann/Snø</span>
            </a>
            <a href="info.php" class="text-slate-400 flex flex-col items-center">
                <i data-lucide="shield-check"></i>
                <span class="text-[9px] mt-1.5 font-black uppercase tracking-tighter">Om oss</span>
            </a>
        </div>
    </nav>
    <script>lucide.createIcons();</script>
</body>
</html>