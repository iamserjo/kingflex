/**
 * Consultant chat page functionality
 * POST /v1/chatboot/message
 */

document.addEventListener('DOMContentLoaded', () => {
    const messagesEl = document.getElementById('chat-messages');
    const form = document.getElementById('chat-form');
    const input = document.getElementById('chat-input');
    const sendBtn = document.getElementById('chat-send');
    const resetBtn = document.getElementById('chat-reset');

    let isSending = false;

    function scrollToBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function createMessageBubble(role, content, metaText = '') {
        const wrapper = document.createElement('div');
        wrapper.className = `message ${role === 'user' ? 'message-user' : 'message-assistant'}`;
        wrapper.textContent = content || '';

        if (metaText) {
            const meta = document.createElement('div');
            meta.className = 'message-meta';
            meta.textContent = metaText;
            wrapper.appendChild(meta);
        }

        return wrapper;
    }

    function addMessage(role, content, metaText = '') {
        const bubble = createMessageBubble(role, content, metaText);
        messagesEl.appendChild(bubble);
        scrollToBottom();
        return bubble;
    }

    function setSending(sending) {
        isSending = sending;
        sendBtn.disabled = sending;
        input.disabled = sending;
        resetBtn.disabled = sending;
        sendBtn.textContent = sending ? 'Thinking…' : 'Send';
    }

    async function apiSend(message, reset = false) {
        const response = await fetch('/v1/chatboot/message', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ message, reset }),
        });

        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const msg = data.message || 'Request failed';
            throw new Error(msg);
        }

        return data;
    }

    function autoResizeTextarea() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 160) + 'px';
    }

    input.addEventListener('input', autoResizeTextarea);

    input.addEventListener('keydown', (e) => {
        // Enter to send, Shift+Enter for newline
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.requestSubmit();
        }
    });

    resetBtn.addEventListener('click', async () => {
        if (isSending) return;

        setSending(true);
        try {
            await apiSend('', true);
            messagesEl.innerHTML = '';
            addMessage('assistant', 'Новый чат начат. Чем могу помочь?');
            input.value = '';
            autoResizeTextarea();
        } catch (err) {
            console.error(err);
            addMessage('assistant', 'Не удалось сбросить чат. Попробуйте ещё раз.');
        } finally {
            setSending(false);
        }
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (isSending) return;

        const text = (input.value || '').trim();
        if (!text) return;

        addMessage('user', text);
        input.value = '';
        autoResizeTextarea();

        setSending(true);
        const pending = addMessage('assistant', '…');

        try {
            const data = await apiSend(text, false);

            const assistant = data.assistant_message || '';
            const usedTools = Array.isArray(data.used_tools) ? data.used_tools.filter(Boolean) : [];

            pending.textContent = assistant;

            if (usedTools.length > 0) {
                const meta = document.createElement('div');
                meta.className = 'message-meta';
                meta.textContent = `tools: ${usedTools.join(', ')}`;
                pending.appendChild(meta);
            }
        } catch (err) {
            console.error(err);
            pending.textContent = `Ошибка: ${err.message || 'unknown'}`;
        } finally {
            setSending(false);
            scrollToBottom();
        }
    });
});


