<?php require_once 'db.php'; ?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send rapport | Værvakt</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>body { background-color: #0f172a; color: white; margin-bottom: 70px; }</style>
</head>
<body>
    <div class="p-6">
        <h2 class="text-2xl font-bold text-sky-400 mb-4">Send værobservasjon</h2>
        <form action="save.php" method="POST" class="space-y-4">
            <div>
                <label class="block text-sm text-slate-400">Ditt navn / Nick</label>
                <input type="text" name="user" class="w-full bg-slate-800 border border-slate-700 p-3 rounded-xl">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-slate-400">Sted</label>
                    <input type="text" name="loc" placeholder="f.eks Grim" class="w-full bg-slate-800 border border-slate-700 p-3 rounded-xl">
                </div>
                <div>
                    <label class="block text-sm text-slate-400">Temp (°C)</label>
                    <input type="number" name="temp" step="0.1" class="w-full bg-slate-800 border border-slate-700 p-3 rounded-xl">
                </div>
            </div>
            <button type="submit" class="w-full bg-sky-500 hover:bg-sky-400 text-white font-bold py-4 rounded-xl transition shadow-lg">SEND RAPPORT</button>
        </form>
    </div>

    <nav class="fixed bottom-0 left-0 right-0 bg-slate-900/95 backdrop-blur-md border-t border-sky-500/30 px-8 py-4 z-50">
        <div class="flex justify-between items-center max-w-md mx-auto">
            <a href="index.php" class="text-slate-400 flex flex-col items-center">
                <i data-lucide="map"></i>
                <span class="text-[10px] mt-1 font-bold">KART</span>
            </a>
            <a href="rapport.php" class="text-sky-400 flex flex-col items-center">
                <i data-lucide="plus-circle"></i>
                <span class="text-[10px] mt-1 font-bold">RAPPORTER</span>
            </a>
            <a href="info.php" class="text-slate-400 flex flex-col items-center">
                <i data-lucide="info"></i>
                <span class="text-[10px] mt-1 font-bold">INFO</span>
            </a>
        </div>
    </nav>
    <script>lucide.createIcons();</script>
</body>
</html>