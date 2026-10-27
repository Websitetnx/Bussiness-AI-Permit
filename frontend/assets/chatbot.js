/**
 * Frontend chat widget. API handling lives in backend/controllers/api/chatbot.php.
 * PERMIT BPLO Smart Assistant — Interactive AI Chatbot Widget
 */
(function () {
  "use strict";

  const chatbotContainer = document.createElement("div");
  chatbotContainer.id = "PERMIT-chatbot-root";
  chatbotContainer.className = "chatbot-root";
  chatbotContainer.innerHTML = `
    <button class="chatbot-trigger" id="chatbotTrigger" type="button" aria-label="Open BPLO Assistant">
      <span class="chatbot-trigger-icon">💬</span>
      <span class="chatbot-trigger-label">BPLO AI Assistant</span>
      <span class="chatbot-unread-dot" id="chatbotUnreadDot"></span>
    </button>
    <div class="chatbot-window" id="chatbotWindow" aria-hidden="true">
      <header class="chatbot-header">
        <div class="chatbot-header-title">
          <div class="chatbot-avatar">🤖</div>
          <div>
            <strong>PERMIT Assistant</strong>
            <small>AI & Rule-Based BPLO Helper</small>
          </div>
        </div>
        <div class="chatbot-header-actions">
          <button class="chatbot-btn-close" id="chatbotClose" type="button" aria-label="Close chat">✕</button>
        </div>
      </header>
      <div class="chatbot-messages" id="chatbotMessages" role="log" aria-live="polite"></div>
      <div class="chatbot-suggestions" id="chatbotSuggestions"></div>
      <form class="chatbot-input-bar" id="chatbotForm">
        <input type="text" id="chatbotInput" placeholder="Ask about requirements, fees, track Ref..." autocomplete="off" required>
        <button type="submit" id="chatbotSend" aria-label="Send message">
          <span>➤</span>
        </button>
      </form>
    </div>
  `;
  document.body.appendChild(chatbotContainer);

  const trigger = document.getElementById("chatbotTrigger");
  const windowEl = document.getElementById("chatbotWindow");
  const closeBtn = document.getElementById("chatbotClose");
  const messagesEl = document.getElementById("chatbotMessages");
  const suggestionsEl = document.getElementById("chatbotSuggestions");
  const formEl = document.getElementById("chatbotForm");
  const inputEl = document.getElementById("chatbotInput");
  const unreadDot = document.getElementById("chatbotUnreadDot");

  let isOpen = false;
  let isSending = false;

  function toggleChat(open) {
    isOpen = typeof open === "boolean" ? open : !isOpen;
    windowEl.classList.toggle("open", isOpen);
    windowEl.setAttribute("aria-hidden", String(!isOpen));
    trigger.classList.toggle("active", isOpen);
    if (unreadDot) unreadDot.style.display = "none";
    if (isOpen) {
      if (messagesEl.children.length === 0) {
        sendInitialGreeting();
      }
      setTimeout(() => inputEl.focus(), 200);
    }
  }

  trigger.addEventListener("click", () => toggleChat());
  closeBtn.addEventListener("click", () => toggleChat(false));

  function escapeHtml(str) {
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  }

  function formatMarkdown(text) {
    let raw = escapeHtml(text);
    // Bold
    raw = raw.replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>");
    // Italic
    raw = raw.replace(/\*(.*?)\*/g, "<em>$1</em>");
    // Code blocks
    raw = raw.replace(/```([\s\S]*?)```/g, "<pre><code>$1</code></pre>");
    // Headings
    raw = raw.replace(/^### (.*$)/gim, "<h4 class='chat-h4'>$1</h4>");
    // Bullet lists
    raw = raw.replace(/^\s*-\s+(.*$)/gim, "<li class='chat-li'>$1</li>");
    raw = raw.replace(/(<li.*<\/li>)/s, "<ul class='chat-ul'>$1</ul>");
    // Newlines to br
    raw = raw.replace(/\n/g, "<br>");
    return raw;
  }

  function appendMessage(sender, text, actionLink = null, actionLabel = null) {
    const bubble = document.createElement("div");
    bubble.className = `chat-bubble chat-bubble-${sender}`;
    let html = `<div class="chat-bubble-content">${formatMarkdown(text)}</div>`;
    if (actionLink && actionLabel) {
      html += `<div class="chat-action-wrap"><a href="${actionLink}" class="chat-action-btn">${escapeHtml(actionLabel)}</a></div>`;
    }
    bubble.innerHTML = html;
    messagesEl.appendChild(bubble);
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function renderSuggestions(list = []) {
    suggestionsEl.innerHTML = "";
    if (!list || !list.length) return;
    list.forEach(itemText => {
      const chip = document.createElement("button");
      chip.type = "button";
      chip.className = "chatbot-chip";
      chip.textContent = itemText;
      chip.addEventListener("click", () => {
        inputEl.value = itemText;
        handleSubmit();
      });
      suggestionsEl.appendChild(chip);
    });
    suggestionsEl.scrollLeft = 0;
  }

  function showTypingIndicator() {
    const typing = document.createElement("div");
    typing.id = "chatTyping";
    typing.className = "chat-bubble chat-bubble-bot chat-typing";
    typing.innerHTML = `<span class="dot"></span><span class="dot"></span><span class="dot"></span>`;
    messagesEl.appendChild(typing);
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function removeTypingIndicator() {
    const typing = document.getElementById("chatTyping");
    if (typing) typing.remove();
  }

  function sendInitialGreeting() {
    showTypingIndicator();
    setTimeout(() => {
      removeTypingIndicator();
      appendMessage("bot", "Hello! 👋 I am **Permit BPLO Smart Assistant**.\n\nI can answer questions about permit requirements, fee formulas, renewal steps, or track your application live!\n\nWhat can I help you with today?");
      renderSuggestions([
        "What are the requirements for a new permit?",
        "How are permit fees calculated?",
        "What if I do not have an Occupancy Permit?",
        "How do I renew my permit?",
      ]);
    }, 400);
  }

  async function handleSubmit(event) {
    if (event) event.preventDefault();
    const query = inputEl.value.trim();
    if (!query || isSending) return;

    appendMessage("user", query);
    inputEl.value = "";
    isSending = true;
    renderSuggestions([]);
    showTypingIndicator();

    try {
      // Resolve correct path to api/chatbot.php
      const baseUrl = window.location.pathname.includes("/admin")
        ? "../api/chatbot.php"
        : "api/chatbot.php";

      const res = await fetch(baseUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ message: query }),
      });

      const data = await res.json();
      removeTypingIndicator();

      if (data && data.reply) {
        appendMessage("bot", data.reply, data.action_link, data.action_label);
        if (data.suggestions) renderSuggestions(data.suggestions);
      } else {
        appendMessage("bot", "Sorry, I am currently unable to process your request. Please try again.");
      }
    } catch (err) {
      removeTypingIndicator();
      appendMessage("bot", "Unable to connect to the assistant server. Please check your network connection.");
    } finally {
      isSending = false;
    }
  }

  formEl.addEventListener("submit", handleSubmit);
})();
