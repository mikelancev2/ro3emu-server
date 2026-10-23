# Chronicles — site local

Landing page trilíngue (português, inglês e espanhol) do servidor Chronicles.

## Rodar localmente

```bash
cd chronicles-site
CHRONICLES_DISCORD_URL='https://discord.gg/seu-convite' php -S localhost:8080
```

Abra `http://localhost:8080`. Troque o idioma pelo seletor PT / EN / ES no topo.

## Configuração dos botões

As URLs são lidas do ambiente, para não deixar links de produção gravados no código:

```bash
export CHRONICLES_DISCORD_URL='https://discord.gg/seu-convite'
export CHRONICLES_DOWNLOAD_URL='https://downloads.seudominio.com/ChroniclesLauncher.exe'
export CHRONICLES_ACCOUNTS_API='http://127.0.0.1:5380/ro3emu/accounts'
export CHRONICLES_GOOGLE_CLIENT_ID=''
export CHRONICLES_GOOGLE_CLIENT_SECRET=''
export CHRONICLES_GOOGLE_REDIRECT_URI=''
```

`CHRONICLES_ACCOUNTS_API` aponta para a API de contas do servidor do jogo, que só aceita chamadas do próprio loopback — o site e o servidor precisam estar na mesma máquina (ou atrás de um túnel que preserve isso). Enquanto estiver vazia, o formulário de cadastro fica visível mas avisa que o cadastro ainda não está ativo; o site nunca grava senha em disco, só repassa pro servidor do jogo.

As três variáveis `CHRONICLES_GOOGLE_*` ligam o botão "Entrar com Google" (atalho de preenchimento do e-mail antes do cadastro normal, não um sistema de conta separado). Vazias, o botão fica oculto.

## Arquivos para a VPS

Envie todo o conteúdo desta pasta para a raiz pública do site (por exemplo, `public_html` ou `/var/www/chronicles`). O servidor web precisa apenas de PHP; não há dependências, banco de dados ou etapa de build.

Para Apache, configure as variáveis acima no virtual host. Caso prefira, substitua diretamente os valores em `site-config.php`.

## Arte

As artes em `assets/client/` são extraídas localmente do cliente do proprietário e a pasta é ignorada pelo Git. Isso evita versionar ou distribuir arquivos do jogo dentro do repositório.

Antes de publicar, copie essa pasta separadamente para a VPS somente se você tiver autorização para usar e distribuir essas artes. Sem ela, a página continua funcional, mas as imagens referenciadas nessa pasta não serão exibidas.
