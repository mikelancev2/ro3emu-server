# Notas de desenvolvimento — sessão de testes e infraestrutura

Log vivo do que foi descoberto, corrigido e montado durante as sessões de teste do RO3Emu.
Objetivo: não repetir investigação já feita, e dar contexto rápido pra quem (ou qual sessão de IA)
continuar o trabalho depois.

## Sincronização com o repositório oficial + client 20 Oct (2026-10-21)

Repo local estava 81 commits atrás do `origin/main` (25+ PRs: suporte ao client de 20 Oct, correções de
segurança/robustez, quality gates). Mesclado com `git merge origin/main` (não `reset --hard`, bloqueado
pelo classificador de segurança por ser destrutivo — `merge` preserva histórico e chegou no mesmo
resultado). Um conflito em `CombatService.Casting.cs`: o upstream renomeou os campos `buffs`→`_buffs` e
`log`→`_log` (convenção de nomenclatura, provavelmente dos PRs de "quality gates"/estilo); nosso log de
diagnóstico de skill usava os nomes antigos e precisou ser atualizado manualmente pós-merge.

**Client atualizado também**: `RO3Emu.exe update` trouxe o build oficial mais novo (release 140073,
`2026.10.20`) — atualização incremental (14 de 37.817 arquivos, 38MB, ~2min), não precisou reinstalar.
Dados do servidor reexportados desse client novo (`Ro3Emu.Setup export`): 516 tabelas, 381 mapas, 2222
skills. **Banco de dados zerado** (local e VPS) de propósito — combinamos que não tem problema perder
personagens de teste nessa fase.

**`global.json` agora fixa o SDK em `10.0.402`** (era `10.0.300`) — precisou instalar essa versão exata
tanto no WSL de dev quanto na VPS (`dotnet-install.sh --version 10.0.402`).

**Teste real (não flaky) ainda não resolvido**: `PowerTests.ChangesPublishAndTheHistoryIsKeptAcrossRelog`
falha de forma reproduzível (não é só carga do sistema — rodei isolado 2x, falhou as 2). O valor ao vivo
bate certo (`Sum(lowered.CurPower)` == `BaseLv(5)` + pacote), mas depois de salvar e recarregar via
`RoleStore`/`EmblemService.EmblemState`, o valor persistido vem **6x maior** (esperado 600, veio 3600).
Os outros 4 testes que falharam no run completo (`PassPlayTests`, `DungeonTests`, `KillEconomyTests`,
`QuestWalkTests.ChapterOneCanBeWalkedToTheEnd`) passaram quando isolados — flakiness de carga concorrente
(mesmo padrão já documentado nessa sessão), não regressão. **Próximo passo se investigar**: olhar
`EmblemService`/`RoleStore` por onde `CurrentNormal`/`CurrentSeason`/`HistoryNormal`/`HistorySeason` são
calculados e persistidos — o fator 6x é estranho o suficiente pra sugerir um bug de escala/unidade, não
só dado desatualizado.

**VPS também atualizada** com o mesmo merge + client novo + dados novos (mesmo passo a passo do
`server.sh`, só que manual: `dotnet publish` + `systemctl restart`). Banco antigo da VPS também apagado.

## VPS de produção (2026-10-21)

Primeiro deploy real do servidor fora da máquina de dev. Credenciais de acesso SSH **não ficam aqui** —
estão na memória do Claude (`vps_access.md`), já que esse arquivo é versionado no git.

**O que já está rodando na VPS:**
- Servidor do jogo (`Ro3Emu.Server`) como serviço systemd `ro3emu`, instalado com
  `./server.sh --host 89.117.75.68 --service` (repo copiado via `rsync`, não `git clone` — foi mandada a
  working tree local com as correções ainda não commitadas). Portas 5380 (HTTP/SDK), 20000 (micro-gate),
  33100 (game-gate) abertas no `ufw` só para essas 3 (a VPS já tinha outras coisas rodando: Apache em
  80/443, outro "DE gameserver" em 9996-9999, não mexemos nisso).
- Repositório de patch do Launcher (chunks/manifests/channels) como serviço systemd `ro3emu-patch-repo`,
  `python3 -m http.server 8790` servindo `/root/ro3emu-patch-repo`. Porta 8790 aberta no `ufw`. **Separado
  do servidor do jogo** — é só arquivo estático, não tem nada a ver com o processo `Ro3Emu.Server`.
- `sqlite3` instalado na VPS pra poder inspecionar `data/ro3emu.db` quando precisar (`PRAGMA
  integrity_check`, contagem de linhas etc.) — não vinha instalado por padrão.
- **Nenhuma conta GM configurada ainda** — igual ao servidor local, isso significa que *qualquer* conta
  que logar tem comandos de GM. Ok pra testar sozinho, mas configurar `--gm <conta>` no `server.sh` (ou
  editar o `ro3emu.service` direto) antes de deixar mais alguém entrar.

**Como qualquer client existente troca de servidor:** `RO3Emu.exe join <ip>` (CLI já pronta do projeto,
`src/Ro3Emu.Setup/Flows.cs:365`). O endereço do servidor **não é um arquivo de config** — fica
hard-coded como string literal dentro das DLLs do jogo (`Happyengine.Network.dll`, `Assembly-CSharp.dll`,
`Phonest.Common.dll`), reescrita pelo `ClientPatcher` a partir de um backup intocado (`ro3emu-backup/`)
toda vez que o patch roda. Isso não é uma gambiarra do RO3Emu — é assim que o client oficial funciona (não
tem opção de trocar servidor na tela de login), então reescrever a DLL é o único jeito de redirecionar.

**Pipeline completo do Launcher gráfico (`Ro3EmuLauncher.exe`) pra um servidor novo:**
1. `Ro3Emu.ClientTools.exe release <client dir> <server url> <gate-key.pem> <repo dir> <version> <channel>`
   — patcheia uma cópia do client pro servidor novo E monta o repositório de patch (chunks comprimidos com
   zstd + dedup por conteúdo: do client de 23GB cru deu **13,3GB/63.813 chunks**). O `gate-key.pem` pode
   ser baixado direto do servidor rodando: `<server url>/ro3emu/gate-key.pem`.
   - **Pegadinha recorrente**: esse comando valida o client contra `.ro3-update/verified-baseline.txt`
     (gerado manualmente — ver seção mais abaixo sobre esse arquivo) e falha se QUALQUER arquivo tiver
     tamanho diferente do registrado — inclusive logs (`Logs/setup.log`, `ro3emu/loc_misses.tsv`) que
     crescem naturalmente só de ter jogado depois de gerar o baseline. Precisa regenerar o baseline com os
     tamanhos atuais antes de rodar `release` de novo (script em PowerShell, mesma lógica do
     `gen_baseline.py` documentado abaixo, mas nativo pra rodar rápido direto no Windows).
2. `dotnet publish src/Ro3Emu.Launcher -c Release -r win-x64 --self-contained -p:PublishSingleFile=true
   -p:IncludeNativeLibrariesForSelfExtract=true -p:EnableWindowsTargeting=true` — o `launcher.json`
   (`src/Ro3Emu.Launcher/launcher.json`) fica **embutido no exe** nesse build (`EmbeddedResource` no
   csproj); editar esse arquivo ANTES de publicar pra apontar `repository`/`server` pro host certo. Um
   `launcher.json` solto do lado do exe tem prioridade sobre o embutido, útil só pra teste rápido.
3. `Ro3Emu.ClientTools.exe release-launcher <repo dir> <channel> <Ro3EmuLauncher.exe> <version>` — publica
   o launcher dentro do mesmo repositório (pro auto-update dele funcionar depois).
4. Copiar o repositório pro host público respeitando a ordem: `chunks/`, `manifests/`, `launcher/` **antes**,
   `channels/` **por último** — senão um jogador pode baixar o `channels/<canal>.json` apontando pra um
   manifest/chunk que ainda não chegou no servidor.
5. **Dois campos de `launcher.json` fazem coisas completamente diferentes** — fácil de confundir:
   - `server` → só o "pingzinho" de online/offline na UI (`<server>/ro3emu/status`). Não afeta pra onde o
     jogo instalado realmente conecta.
   - `repository`/`channel` → o sistema de instalação/atualização de verdade (fetch de chunks). **O que
     realmente redireciona o jogador é o passo 1** (`release`), não o `launcher.json`.

**Resultado atual**: client em `http://89.117.75.68:5380`, repositório em `http://89.117.75.68:8790/`,
canal `live`, client versão `1.0.0`, launcher versão `1.0.1`. Testado de fora da VPS com `curl` (não só
localhost) — `channels/live.json` e o download do `Ro3EmuLauncher.exe` respondem certo.

**Resto pendente**: `%APPDATA%\RO3Emu\launcher-settings.json` tinha um `clientDirectory` apontando pra
`F:\RO3Emu-Test\Client`, que foi apagado na limpeza de disco — removido esse arquivo pra ele perguntar de
novo onde instalar na próxima vez que abrir o launcher.

## Ambiente de dev (WSL + Windows)

Este repo roda num WSL (Linux) que compartilha a máquina com o Windows onde o client/jogo rodam.
Ferramentas instaladas localmente (sem root, user-level):

- **.NET 10 SDK** (10.0.300) — `~/.dotnet`, `PATH="$HOME/.dotnet:$PATH" DOTNET_ROOT="$HOME/.dotnet"`
- **JDK 21** (Temurin) — necessário pro `unluac` (descompilador Lua). Baixado em `/tmp/.../scratchpad/jdk` (scratchpad é temporário, reinstalar se precisar)
- **unluac.jar** — descompilador Lua 5.4 (https://sourceforge.net/projects/unluac/), usado via `tools/unluac-batch/BatchUnluac.java`

Client oficial do Genesis instalado em `C:\Users\estac\Desktop\RO3Emu-OfficialClient` (baixado via
`Ro3Emu.Setup install`, ~37.815 arquivos, release sequence 140067). **Esse é o client de referência pra
dev** — compilar `ClientHooks` (`RO3_CLIENT_DIR=<essa pasta>`), re-patchear, gerar dumps Lua. Não apagar.

### Compilar pra Windows a partir do WSL

Qualquer projeto Windows-only (`ClientTools`, `Setup`/`RO3Emu.exe`, `Launcher`) builda normalmente com
`dotnet publish -r win-x64 --self-contained -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true`.
O `Launcher` (WPF) precisa de `-p:EnableWindowsTargeting=true` pra buildar (não dá pra *rodar*/testar ele
no Linux, só publicar).

### Rodar comandos no Windows a partir do WSL

`powershell.exe -Command '...'` funciona bem (localhost forwarding do WSL2 já deixa `127.0.0.1` acessível
dos dois lados). Cuidado: aspas duplas dentro de `-Command` usam `$_` do jeito do PowerShell — usar aspas
simples no bash pra evitar o bash expandir `$_` antes.

## Pipeline de descompilação do client (Lua)

Processo completo, reutilizável pra qualquer investigação futura:

1. `dotnet run --project tools/LuaDecode -c Release -- <clientDir>/ro3_Data/StreamingAssets/Recovery/LuaPayload <out>` —
   decodifica os chunks Lua ofuscados pra bytecode Lua 5.4 padrão (13.488 arquivos, ~instantâneo)
2. `java -cp unluac.jar:<BatchUnluac.class dir> BatchUnluac <bytecode dir> <out> [threads]` — descompila
   bytecode pra Lua legível (uma JVM só, todos os arquivos)

**Já rodado uma vez para o client inteiro**, resultado em `re/lua/` (pasta no gitignore, "client research",
13.477 de 13.488 arquivos ok). **Usar isso primeiro** antes de reinvestigar/copiar arquivos avulsos — é
só `grep -rn "..." re/lua/`.

## Log de diagnóstico de skills no servidor

Adicionado em `src/Ro3Emu.Server/Game/CombatService.Casting.cs` (`CastCoreAsync`/`RefuseCoreAsync`):
toda tentativa de usar skill agora loga `{Nome} casts skill {Id} ({ScriptLogic})` (sucesso) ou
`{Nome} skill {Id} refused: {Error} ({Param})` (recusa), mais um caso de corrida de condição que antes
retornava silenciosamente. **Isso deveria ter sido feito desde o início** — sem isso, qualquer "a skill
não funciona" vira uma investigação de 30+ minutos comparando contadores de métricas antes/depois.

Outra ferramenta útil pra isso: `curl http://127.0.0.1:5380/ro3emu/metrics` (loopback), mostra contagem
de mensagens por tipo (`inbound.<msgId>.count`) — dá pra ver se o client **mandou** um pedido mesmo sem
log específico.

## Bugs encontrados e corrigidos

### 1. Soul Destroyer (Guillotine Cross, skill 151003) não avançava nem dava dano
- Causa: faltava na tabela `Displacements` em `CombatService.Effects.cs` (lista manual de quais skills têm
  dash/empurrão). Script interno chama-se `EatChicken_1003` (nome de dev esquisito, mas confirmado único
  dessa skill).
- `MoveCasterAsync` roda antes de `ApplyEffectsAsync`; sem avançar, o personagem fica longe demais e o
  dano não aplica — os dois sintomas (não avança + não dá dano) vêm da mesma causa.
- Corrigido: `["EatChicken_1003"] = (DisplaceKind.CasterForward, 0)`

### 2. Flowing Shadow / Fluxo Sombrio (skill 15421) — mesmo bug
- Script `yingliu`, também faltava na tabela `Displacements`.
- Corrigido: `["yingliu"] = (DisplaceKind.CasterForward, 0)`
- **Padrão a reaplicar**: qualquer skill cuja descrição diga algo como "avança Xm" e tenha
  `m_kPoint.m_bMovement=true` em `data/skills/Lua_Config_EffectData.json` no range
  `scriptShow*100..scriptShow*100+99`, mas não esteja na tabela `Displacements`, provavelmente tem esse
  bug. Já foi feita uma varredura completa (ver seção "Pendências" — "Dark Illusion" tem o mesmo padrão
  mas requer lógica nova, não é fix de 1 linha).

### 3. Auto-uso de poção (medicina) crashava o client
- `CharacterService.Sync.cs`, método `BuildItems`: `info.MedicinesAutoUse = preferences.MedicinesAutoUse?.Clone();`
  mandava `null` quando o jogador nunca configurou — o client crashava (`bad argument #1 to 'floor'`,
  `attempt to index a nil value`) tentando ler o valor.
- Corrigido: default pra `MedicinesAutoUseSet` com `Condition=50` em ambos HP/SP quando nulo.

## PRs mesclados localmente (main local, não enviado ao remoto ainda)

PR #10 (voice off hint), #11 (voice encryption), #12 (voice port launcher), #14 (guild voice panel) — sem
conflitos. Branches ainda existem: `pr-10-review`, `pr-11-review`, `pr-12-review`, `pr-14-review`.

## Infraestrutura do launcher profissional (`Ro3EmuLauncher.exe`)

Já existia no projeto (`src/Ro3Emu.Launcher`), só não estava montado/testado. Pipeline completo validado
hoje:

1. **Gerar baseline do client**: `RO3Emu.exe install` não escreve `.ro3-update/verified-baseline.txt`
   (só o launcher oficial da Gravity faz isso) — isso bloqueia `Ro3Emu.ClientTools release`. **Gap real do
   projeto**, não documentado. Contornado gerando esse arquivo manualmente (formato: header
   `RO3RECOVERYVERIFIEDBASELINE1` + 2 linhas livres + `{size}\t-\t{path relativo}` por linha; os campos do
   meio nunca são validados por `ReadBaseline` em `ReleaseBuilder.cs`). Script em
   `/tmp/.../scratchpad/gen_baseline.py` (scratchpad, perdido entre sessões — recriar se precisar, é
   simples).
2. `Ro3Emu.ClientTools release <clientDir> <serverUrl> <gate-key.pem> <repoDir> <version> <channel>` —
   publica o client (chunking, ~14GB comprimidos de 22GB). **Demora** (minutos), roda sem log até o fim.
3. `Ro3Emu.ClientTools release-launcher <repoDir> <channel> <launcher.exe> <version>` — publica o launcher
   em si.
4. Servir `repoDir` por HTTP (`python3 -m http.server 8790`) — bate com o `launcher.json` padrão
   (`repository: http://localhost:8790/`).
5. Launcher aponta a instalação via `%APPDATA%\RO3Emu\launcher-settings.json` (`{"clientDirectory": "..."}`)
   — útil pra apontar pra um client já existente em vez de baixar do zero.

**Fluxo confirmado funcionando**: abrir launcher → Install → (após verificar) → Play → jogo abre direto,
sem tela preta, sem PowerShell visível pro jogador. É exatamente a UX de um launcher comercial.

**Nota de performance**: a verificação ("Checking files") faz hash SHA256 completo de cada arquivo —
pode ser lenta em discos lentos/com antivírus escaneando. É custo único (primeira vez que reconhece a
pasta); próximas aberturas deveriam ser rápidas via `state.json` salvo. Se for copiar arquivos manualmente
pra pular o download (em vez de deixar o launcher baixar), ainda assim ele vai querer re-hashear pelo
menos uma vez pra confiar no conteúdo — não tem como pular isso sem fabricar o `state.json` también (não
tentado ainda, ver `LocalState.cs` pro formato caso valha a pena automatizar).

## Como abrir o jogo (client oficial, patcheado) sem tela preta fechando

O `ro3.exe` exige uma "prova de launcher" (named pipe herdado, `RO3.ClientSecurity.LauncherProofGuard`,
senão sai com código 70). Clicar direto no `.exe` falha. Tem que ser via `Launcher.Start`/`GameStarter`
(mesma lógica em `Ro3Emu.ClientTools`, `Ro3Emu.Setup` e `Ro3Emu.Launcher`) — todas as 3 ferramentas já
resolvem isso sozinhas. Da WSL, isso significa publicar o `.exe` pra Windows e rodar `launch`/`join` por
lá (não dá pra fazer a ponte WSL→Windows pra esse pipe específico de dentro do `dotnet run` do Linux).

## Pendências / investigações abertas (não resolvidas, catalogadas pra depois)

### Árvore de skills do Mercador (e outras) com buracos de dados
`SkillTreeConfig.json` referencia ScriptShow que não existem em `SkillConfig.json` — **conteúdo
genuinamente ausente no client oficial**, não é bug nosso, não tem como "baixar" uma versão com esses
dados (o instalador só pega a versão atual do publisher; conferido, não dá pra pedir versão antiga).
Levantamento feito:
- Mercador (6100): 5 de 8 faltando
- Ferreiro (6200): 3 de 7 faltando
- Mestre Ferreiro (6300): completo
- Mecânico (6400): 5 de 41 faltando

Opção real: recriar essas skills como conteúdo "inferido" (mesma categoria de outras partes do projeto já
marcadas assim) — decisão de escopo maior, não iniciada.

### Dark Illusion (skill 15401, `hesehuanying`) — mesmo sintoma do Soul Destroyer, fix diferente
Tem "Charges to a point X meters in front of the target" — ao contrário das outras skills de avanço (que
andam uma distância fixa na direção que o personagem olha), essa precisa and**até perto de um alvo
específico** (distância variável dependendo de onde o inimigo está). O mecanismo atual de `MoveCasterAsync`
só suporta "andar X metros numa direção fixa" — não dá pra simplesmente adicionar na tabela `Displacements`
com o mesmo padrão, precisaria de lógica nova (calcular distância até o alvo, não usar `move.Distance`
fixo). Não implementado.

### Skills direcionais ("Arrow" indicator) não funcionam no modo de controle "Normal"
Fluxo Sombrio/Recuo Sombrio e qualquer skill com `m_iSkillIndicator = 4` ("Arrow", ver
`Lua_GameDefine.lua` pro enum completo: Lock=0, FixCircle=1, FixRect=2, Fan=3, **Arrow=4**, Rect360=5,
DragRect=6, DragCircle=7...) não mandam pedido nenhum pro servidor no modo "Normal" de controle (confirmado
via métricas: zero pedidos, não importa clique único/duplo/segurar). **Funciona perfeitamente no modo
"Configuração Rápida"** — é a correção/workaround atual. A causa exata no client não foi encontrada (função
`_doSkillBtnDown`/`_checkSkillCanCast`/`_getSkillEditorData` em
`UI_Sys_Main_JoyStickSkillItemWidget_PCCtrl.lua` foram lidas, mas o gatilho exato do gesto de arrastar não
foi localizado). Não é bug do nosso servidor — é puramente client-side.

### Efeitos visuais (VFX) de algumas skills não aparecem (ex: Crazy Uproar, skill 1600810)
Mecânica funciona 100% (buffs aplicados corretamente, confirmado no log). O dado de efeito existe
(`fx_blacksmith_shout.prefab`, bind `HeadTop`) em `Lua_Config_EffectData.json`. Rastreamento parcial:
`LC_PerformEffect_Agent.lua` tem uma função `CreateLink` (~linha 1574) que lê `GetEffectInfo(effectId)` e
checa visibilidade por `m_effectShowType` (Self/TeamFriend/GroupFriend) antes de tocar o efeito — não
achamos ainda quem chama essa função com qual `effectId`, nem se o campo de visibilidade está classificando
errado. **Provavelmente afeta várias skills de buff**, não só essa. Ver seção abaixo sobre a ferramenta de
assets — o próximo passo lógico é confirmar se o prefab em si existe no VFS antes de rastrear mais Lua.

### Ferramenta de inspeção de assets Unity (.bundle) — AssetRipper instalado, achado um gargalo real

**AssetRipper** (github.com/AssetRipper/AssetRipper, GPL-3.0) baixado via `gh release download --repo
AssetRipper/AssetRipper`, build `AssetRipper_linux_x64.tar.xz` da release atual. Roda headless com API
web: `./AssetRipper.GUI.Free --headless --port <porta>`, depois `curl -X POST http://127.0.0.1:<porta>/LoadFolder -d "path=<pasta>"`
(form-urlencoded, campo `path`). Doc OpenAPI em `/openapi.json`, Swagger em `/swagger`.

**Gargalo real encontrado**: apontar o AssetRipper pra pasta do client **montada do Windows** (`/mnt/c/...`)
trava a etapa "Processing loaded assets" quase parado (CPU ~5%, thread preso em `p9_client_rpc` — o
protocolo 9P que o WSL usa pra acessar `/mnt/c`/`/mnt/f`). Confirmado via `ps -L -p <pid>` olhando o
`WCHAN` das threads. **Mesmo gargalo que já tínhamos visto na verificação de hash do launcher** — leitura
dispersa de muitos arquivos pequenos pela ponte WSL↔Windows é ordens de magnitude mais lenta que disco
nativo.

**Correção pra próxima vez**: copiar a pasta do client (ou só `ro3_Data`) pro filesystem nativo do WSL
(ex: `/home/cumbe11/...`, 868GB livres) **antes** de rodar qualquer ferramenta que vá ler/hashear muitos
arquivos pequenos. Confirmados os 13.488 arquivos Lua foram montados do Windows sem problema pro
`LuaDecode` (bytecode é leitura sequencial simples, rápido mesmo pela ponte) — o problema é especificamente
com ferramentas que fazem **muitas leituras pequenas/aleatórias** (hash de arquivo por arquivo, parsing de
asset bundle por bundle). Regra geral: leitura sequencial grande = ok pela ponte; muitos arquivos pequenos
ou acesso aleatório = copiar pro nativo primeiro.

**Atualização — gargalo resolvido, mas busca por nome não serve pra VFX**: copiado `ro3_Data` (23GB) pra
`~/ro3emu-client-local/ro3_Data` via `rsync -a` (nativo WSL, ~15min, bem mais rápido que a ponte). Apontar
o AssetRipper pra essa cópia local processa em segundos em vez de travar — confirma 100% que o gargalo era
mesmo a ponte 9P.

Busca funciona bem via `GET /Search/View?q=<termo>` (parâmetro é `q`, não `Query`) pra ícones/sprites (ex:
`q=blacksmith` achou 58 resultados reais, `icon_buff_mastersmith_shoutuichejiasu` etc.) — mas **teste de
controle**: buscar por `indicator` (a seta de mira `fx_ui_indicator_arrow_large`, que **sabemos que
funciona** no jogo) só retornou 3 `MonoScript` (classes C#), não o prefab em si. Ou seja, **a busca por
nome do AssetRipper não indexa GameObjects/Prefabs de VFX de forma confiável** — não dá pra concluir que
um efeito "não existe" só por não aparecer na busca. Pra confirmar presença/ausência de um prefab
específico seria preciso abrir o bundle certo e navegar a hierarquia de GameObjects manualmente (via
`/Bundles/View` ou `/Collections/View`), não só buscar por nome — mais trabalho, não tentado ainda.

**Conclusão da investigação do VFX da Crazy Uproar**: pausada por falta de tempo/retorno, não por
impossibilidade. Mecânica (buffs) confirmada 100% funcional pelo log do servidor, **testado ao vivo
várias vezes** (não só por log). Efeito visual confirmado **ausente em jogo** (testado ao vivo, qualidade
gráfica Média e Alta, sem diferença — não é configuração de qualidade/performance). Causa raiz técnica
não encontrada: ferramenta de assets (AssetRipper) carrega o client mas não resolve todas as dependências
entre bundles (muitos "Dependency ... wasn't found" no log de import), então busca por nome não é
confiável pra confirmar presença/ausência do prefab. Próximo passo, se retomado: abrir os bundles
manualmente pela hierarquia (`/Bundles/View`) em vez de só buscar por nome, ou investir tempo resolvendo
as dependências faltantes do import.

**Atualização — caminho técnico real encontrado pra debugar isso de vez**: descompilei
`Assembly-CSharp.dll` com `ilspycmd` (`dotnet tool install -g ilspycmd`) procurando a função C# que o Lua
chama pra carregar um prefab de efeito. Não existe uma função C# específica — o client usa **XLua**
(framework Tencent, `./XLua` no assembly descompilado), que expõe `UnityEngine.*` **direto** pro Lua via
`CS.UnityEngine.Resources.Load(...)` etc. Ou seja, não tem "wrapper" próprio do jogo pra patchear — é a
API do Unity mesma.

**Atualização — complicador real encontrado, plano revisado**: verifiquei se existe um wrapper gerado
pelo XLua pra `UnityEngine.Resources`/`AssetBundle` (procurei por `*Wrap.cs` em todo o assembly
descompilado) — **só existe 1 arquivo Wrap no assembly inteiro** (`DelegateBridge_Wrap.cs`, infra interna
do XLua, não um wrapper de tipo do jogo). Isso confirma que o XLua roda em **modo reflection pura** pra
chamadas Lua→C# desse tipo — não existe um ponto de chamada fixo (`call Resources::Load`) em IL pra
patchear com Cecil do jeito que patcheamos Assembly-CSharp normalmente.

Pra interceptar isso de verdade seria preciso patchear o **despachante genérico de reflection do XLua**
(afeta TODA chamada Lua→C# do jogo, não só carregamento de efeito) — risco alto de quebrar o jogo inteiro
se mal feito, e exigiria testes cuidadosos antes de confiar no patch. **Pausado aqui por decisão
consciente** (não por limite técnico de ferramenta) — é trabalho real, só não é o tipo de coisa pra fazer
com pressa no fim de uma sessão longa sem poder testar com calma depois.

**Se retomar**: o ponto de entrada seria a classe XLua que trata chamadas de método genéricas via
reflection (procurar por `ObjectTranslator`/`InvokeSession`/algo que chame `MethodInfo.Invoke` no
assembly XLua descompilado — não totalmente mapeado ainda). Filtrar o log só por nomes de asset
começando com "fx_" seria essencial pra não gerar log demais.

**Padrão a verificar em skills futuras com o mesmo sintoma** (ex: usuário mencionou suspeita de "Mannita"
também): 1) confirmar a skill é buff puro (dmgType=0, sem alvo), 2) confirmar no log do servidor que o
buff é concedido corretamente, 3) se a mecânica funciona mas o efeito não aparece, é o mesmo padrão — não
precisa reabrir a investigação técnica inteira, só catalogar como mais um caso do mesmo problema ainda não
resolvido.

### (resto da seção original abaixo, ainda relevante)
VFX, prefabs, etc. são assets binários do Unity, não Lua — ferramenta separada necessária. **Já temos
metade do caminho**: `src/Ro3Emu.DataExport/Vfs.cs` (usado hoje só pra mapas de navegação) já resolve
`nome do asset → bytes/arquivo .bundle` via hash MurmurHash3 x64, lendo
`ro3_Data/StreamingAssets/VFS/assetmeta.data`. Dá pra reusar direto (`Vfs.Extract(name)` /
`Vfs.SourceFile(name)`) pra confirmar se um asset **existe** no client. Pra inspecionar o **conteúdo** de
um `.bundle` (componentes do prefab, sistema de partículas etc.) ainda precisaria de um parser de verdade
do formato serializado do Unity (ex: AssetRipper, não instalado ainda).
Projeto de teste criado em `/tmp/.../scratchpad/vfscheck/` (scratchpad, recriar se precisar — é só um
`Program.cs` de ~10 linhas usando `Vfs` do `Ro3Emu.DataExport`).

**Atualização — resultado definitivo: VFS NÃO é o sistema onde VFX fica guardado.** Testei `Vfs.cs` contra
`fx_blacksmith_shout.prefab`/`fx_ui_indicator_arrow_large.prefab` (variações de nome incluídas) → `NOT
FOUND` pros dois. Antes de concluir qualquer coisa, validei a ferramenta com um controle positivo
conhecido (`sc_prt_fild08_001_dynamicmapdata.bytes` → achou certo, 708784 bytes) — a ferramenta funciona.
Depois rodei o teste de controle decisivo: `Fx_UI_jihuo`, um GameObject que o **AssetRipper confirmou
existir de verdade** (achado via busca `q=fx_`) → **também `NOT FOUND` na VFS**, em todas as variações de
nome testadas. Conclusão: VFX simplesmente **não é indexado pela VFS** (que serve pra conteúdo hot-patch:
Lua, mapas, textos) — fica nos asset bundles normais do Unity. Isso invalida a VFS como teste de
presença/ausência pra VFX; os resultados `NOT FOUND` de antes não provam nada sobre o `fx_blacksmith_shout`
especificamente.

**Estado final da investigação (pausada, não abandonada)**: com os dois caminhos de busca por nome (VFS e
busca textual do AssetRipper) descartados como não confiáveis pra VFX, e só 8 objetos `Fx_*` indexados no
AssetRipper em 22GB de dados (número claramente baixo pra um jogo desse tamanho — bate com os vários
"Dependency ... wasn't found" vistos no log de import), a evidência pesa um pouco mais pra "dados de bundle
incompletos no import" do que pra "efeito realmente ausente do client" — mas nenhuma das duas está provada.
Resolver de vez exigiria ou (a) navegar manualmente a hierarquia de bundles via `/Bundles/View`/
`/Collections/View` em vez de busca por nome, ou (b) investigar por que tantas dependências entre bundles
não resolvem no import do AssetRipper. Nenhum dos dois tentado ainda — próximo passo se retomado.

**Limpeza de disco (2026-10-20)**: apagadas as cópias temporárias usadas só pra essa investigação —
`~/ro3emu-client-local` (cópia nativa WSL do `ro3_Data`, 23GB, só servia pro AssetRipper rodar rápido),
repositório de patch temporário + binário do AssetRipper no scratchpad (14GB), e `F:\RO3Emu-Test\Client`
(23GB, cópia de teste do launcher já validada e não mais necessária). **Ficou só**
`C:\Users\estac\Desktop\RO3Emu-OfficialClient` (23GB) — client de referência pra dev, não apagar. Se a
investigação for retomada, recriar a cópia nativa com `rsync -a` (~15min) e reinstalar o AssetRipper via
`gh release download`.

### Barra de atalho "não aceita" item ao arrastar — CORRIGIDO (bug real, causa client + fix server-side)

Sintoma relatado: arrastar uma poção pra um slot **vazio** da barra de atalho parecia não funcionar (não
aparecia na tela até trocar de tela/outra ação). Um slot que já tinha algo antes atualizava na hora.

Investigação: log de diagnóstico em `ShortcutBarSaveAsync` (`CharacterService.RoleOperations.cs`) mostrou
**toda tentativa retornando `result: 0` (sucesso)** — nunca foi recusa do servidor. Descartamos a validação
client-side de tipo de item (`CheckCanPutIn` em `LC_ShortcutBarHelper.lua`, baseada em `_iType`/`_iSubType`
do `ItemConfig`) — os dados batiam certo.

**Causa raiz real, achada em `LC_DAL_Item.lua`**: o client registra `OnShortcutBarInfosUpdate` como handler
de sync incremental pro campo `shortcutBarInfos` (`RegisterAutoSyncUpdateHandler(..., selfAndChild, ...)`).
Essa função só **substitui** a entrada de um slot se já existir uma entrada ali (`if A4_2[slotIndex] then
... end` — sem `else`, silenciosamente não faz nada se for `nil`). Como nosso servidor só mandava
`ShortcutBarInfos` com entradas pros slots **ocupados** (`BuildItems`, `CharacterService.Sync.cs`), um slot
nunca usado não tinha entrada nenhuma — então a primeira vez que um item entrava ali, o handler incremental
encontrava `nil` e desistia. Só atualizava depois de um refresh completo da tela (que repopula tudo do
zero, não passa pelo caminho incremental). Bug real do client oficial (função sem `else`), mas **com fix
possível 100% do nosso lado**: mandar os 12 slots sempre, inclusive vazios com `ItemGuid=0`, pra o array do
client nunca ter um `nil` nesses índices. Implementado em `BuildItems` — poucas linhas, sem mudar como é
persistido no banco, só como é montada a mensagem de sync. **Confirmado pelo usuário em teste ao vivo: o
atraso sumiu.**

Nota (`_processShortcutBarSaveResp` em `LC_DAL_Item.lua` é uma função **vazia** — não faz nada ao receber a
resposta do save. Achado no caminho da investigação, mas não era a causa; o fix passou pelo caminho de sync
incremental, não pela resposta direta do comando.

## Convenções descobertas / armadilhas

- **Nomes de script internos não são confiáveis**: skills reais podem ter `_kScriptLogic` tipo
  `EatChicken_1003` sem relação nenhuma com o nome real da skill — sempre confirmar pelo `_iID`/descrição,
  nunca assumir pelo nome do script.
- **Metrics `completed`/`failed` não distingue sucesso de recusa de jogo** — uma skill "recusada" (SP
  insuficiente, cooldown, etc.) ainda conta como `completed` nas métricas HTTP, só falha em exceções reais
  de processamento. Usar o log de diagnóstico de skill (ver acima) pra saber o resultado de verdade.
- **Sobrescrever o binário do servidor não afeta a sessão já rodando** (Linux mantém o inode antigo em
  memória) — pode recompilar à vontade, só precisa reiniciar o processo pra aplicar.
- **`!hp <hp> <sp>`** é comando GM existente pra forçar resync de HP/SP (`GmCommands.cs`).
