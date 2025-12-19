import { ref, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import echo from '@/echo'
import { usePage } from '@inertiajs/vue3'

interface NotificationData {
  salesDueToday?: Array<any>
  lowStockProducts?: Array<any>
  expiringProducts?: Array<any>
}

// Variable globale pour stocker le contexte audio
let audioContext: AudioContext | null = null
let audioContextInitialized = false

// Fonction pour initialiser le contexte audio (nécessite une interaction utilisateur)
const initAudioContext = (): AudioContext | null => {
  // Si le contexte existe et n'est pas fermé, le retourner
  if (audioContext && audioContext.state !== 'closed') {
    return audioContext
  }
  
  // Si le contexte n'a pas encore été initialisé, ne pas le créer ici
  // Il sera créé après une interaction utilisateur
  if (!audioContextInitialized) {
    return null
  }
  
  try {
    audioContext = new (window.AudioContext || (window as any).webkitAudioContext)()
    return audioContext
  } catch (error) {
    console.error('Erreur lors de l\'initialisation du contexte audio:', error)
    return null
  }
}

// Fonction pour activer le contexte audio après une interaction utilisateur
const activateAudioContext = async (): Promise<AudioContext | null> => {
  try {
    // Si le contexte n'existe pas, le créer
    if (!audioContext || audioContext.state === 'closed') {
      audioContext = new (window.AudioContext || (window as any).webkitAudioContext)()
      audioContextInitialized = true
    }
    
    // Si le contexte est suspendu, le reprendre
    if (audioContext.state === 'suspended') {
      await audioContext.resume()
    }
    
    audioContextInitialized = true
    return audioContext
  } catch (error) {
    console.error('Erreur lors de l\'activation du contexte audio:', error)
    return null
  }
}

// Fonction pour jouer un son de notification
const playNotificationSound = () => {
  try {
    // Vérifier si le contexte audio est disponible et activé
    if (!audioContext || audioContext.state === 'closed') {
      // Le contexte n'a pas encore été activé par une interaction utilisateur
      // On ne peut pas jouer de son pour l'instant
      return
    }
    
    // Si le contexte est suspendu, essayer de le reprendre
    if (audioContext.state === 'suspended') {
      audioContext.resume().then(() => {
        playSound(audioContext!)
      }).catch((error) => {
        console.error('Erreur lors de la reprise du contexte audio:', error)
      })
    } else {
      playSound(audioContext)
    }
  } catch (error) {
    console.error('Erreur lors de la lecture du son:', error)
  }
}

// Fonction pour jouer le son avec Web Audio API
const playSound = (ctx: AudioContext) => {
  try {
    // Créer deux oscillateurs pour un son plus riche (accord)
    const oscillator1 = ctx.createOscillator()
    const oscillator2 = ctx.createOscillator()
    const gainNode = ctx.createGain()
    
    // Connecter les oscillateurs au gain node puis à la sortie
    oscillator1.connect(gainNode)
    oscillator2.connect(gainNode)
    gainNode.connect(ctx.destination)
    
    // Configuration du premier son (note principale)
    oscillator1.frequency.value = 800 // Fréquence en Hz (son aigu)
    oscillator1.type = 'sine' // Type d'onde (sine = son doux)
    
    // Configuration du deuxième son (harmonique pour un son plus riche)
    oscillator2.frequency.value = 1000 // Légèrement plus aigu
    oscillator2.type = 'sine'
    
    // Configuration du volume avec une enveloppe ADSR améliorée
    const now = ctx.currentTime
    const attackTime = 0.01 // Temps d'attaque (10ms)
    const decayTime = 0.05 // Temps de décroissance (50ms)
    const sustainLevel = 0.2 // Niveau de sustain (20%)
    const releaseTime = 0.1 // Temps de relâchement (100ms)
    const totalDuration = attackTime + decayTime + releaseTime // ~160ms
    
    // Enveloppe ADSR
    gainNode.gain.setValueAtTime(0, now)
    gainNode.gain.linearRampToValueAtTime(0.4, now + attackTime) // Attaque rapide
    gainNode.gain.exponentialRampToValueAtTime(sustainLevel, now + attackTime + decayTime) // Décroissance
    gainNode.gain.setValueAtTime(sustainLevel, now + attackTime + decayTime) // Sustain
    gainNode.gain.exponentialRampToValueAtTime(0.01, now + totalDuration) // Relâchement
    
    // Jouer les sons
    oscillator1.start(now)
    oscillator2.start(now)
    oscillator1.stop(now + totalDuration)
    oscillator2.stop(now + totalDuration)
    
    // Nettoyer après la fin
    oscillator1.onended = () => {
      oscillator1.disconnect()
    }
    oscillator2.onended = () => {
      oscillator2.disconnect()
    }
  } catch (error) {
    console.error('Erreur lors de la génération du son:', error)
  }
}

export const useRealtimeNotifications = () => {
  const page = usePage()
  const userId = (page.props.auth as any)?.user?.id

  const notifications = ref<NotificationData>({
    salesDueToday: [],
    lowStockProducts: [],
    expiringProducts: []
  })

  const isListening = ref(false)

  const startListening = () => {
    if (!userId) {
      return
    }
    
    if (isListening.value) {
      return
    }

    try {
      // Vérifier que Echo est disponible
      if (!echo) {
        console.error('Echo n\'est pas disponible. Vérifiez que Pusher est correctement configuré.')
        console.error('Vérifiez que VITE_PUSHER_APP_KEY est défini dans .env et que les assets ont été recompilés avec npm run build')
        return
      }
      
      // Vérifier que la clé Pusher est définie
      const pusherKey = (window as any).Echo?.connector?.pusher?.key
      if (!pusherKey) {
        console.error('La clé Pusher n\'est pas configurée. Vérifiez VITE_PUSHER_APP_KEY dans .env et recompilez les assets.')
        return
      }
      
      // Écouter le canal privé de l'utilisateur
      const channelName = `user.${userId}.notifications`
      const channel = echo.private(channelName)
      
      // Écouter les événements de connexion au canal
      channel.subscribed(() => {
        isListening.value = true
      })
      
      channel.error((error: any) => {
        console.error('Erreur lors de la souscription au canal:', error)
        if (error.status === 403) {
          console.error('Accès refusé: Vérifiez les permissions dans routes/channels.php')
        } else if (error.status === 404) {
          console.error('Canal non trouvé: Vérifiez que la route /broadcasting/auth est accessible')
        }
        isListening.value = false
      })
      
      // Écouter les notifications avec le bon nom d'événement
      // Laravel Echo ajoute automatiquement le préfixe du broadcaster
      // Pour Pusher, l'événement 'notification.sent' devient '.notification.sent'
      channel.listen('.notification.sent', (data: any) => {
        // Mettre à jour les notifications
        if (data.notification) {
          // Jouer un son de notification immédiatement
          playNotificationSound()
          
          // Déclencher un événement personnalisé pour mettre à jour les notifications
          window.dispatchEvent(new CustomEvent('notification-received', { 
            detail: data.notification 
          }))
          
          // Recharger les notifications depuis le serveur après un court délai
          // pour permettre au son de se jouer et obtenir les données complètes
          // Utiliser un délai plus long pour les produits expirés pour s'assurer que la base de données est à jour
          const delay = data.notification?.type === 'expiring_product' ? 1000 : 500
          setTimeout(() => {
            router.reload({ 
              only: ['notifications'],
              preserveScroll: true,
              preserveState: false, // Ne pas préserver l'état pour forcer la mise à jour complète
              onSuccess: (page) => {
                const notifications = page.props.notifications as any
                
                // Forcer la mise à jour en déclenchant un événement personnalisé
                window.dispatchEvent(new CustomEvent('notifications-updated', {
                  detail: notifications
                }))
              },
              onError: (errors) => {
                console.error('Erreur lors du rechargement des notifications:', errors)
              },
              onFinish: () => {
                // Fin silencieuse
              }
            })
          }, delay)
        }
      })
    } catch (error) {
      console.error('Erreur lors de l\'écoute des notifications:', error)
      isListening.value = false
    }
  }

  const stopListening = () => {
    if (!userId || !isListening.value) return

    try {
      echo.leave(`user.${userId}.notifications`)
      isListening.value = false
    } catch (error) {
      console.error('Erreur lors de l\'arrêt de l\'écoute:', error)
    }
  }

  onMounted(() => {
    if (userId) {
      startListening()
      
      // Fonction pour activer le contexte audio après une interaction utilisateur
      const activateAudio = () => {
        activateAudioContext().catch((error) => {
          console.error('Erreur lors de l\'activation de l\'audio:', error)
        })
      }
      
      // Écouter plusieurs types d'événements pour activer l'audio
      // Utiliser { once: false } pour permettre plusieurs activations si nécessaire
      document.addEventListener('click', activateAudio, { once: false, passive: true })
      document.addEventListener('keydown', activateAudio, { once: false, passive: true })
      document.addEventListener('touchstart', activateAudio, { once: false, passive: true })
      document.addEventListener('mousedown', activateAudio, { once: false, passive: true })
    }
  })

  onUnmounted(() => {
    stopListening()
  })

  return {
    notifications,
    startListening,
    stopListening,
    isListening,
    // Exposer la fonction pour tester le son
    testSound: () => {
      playNotificationSound()
    }
  }
}

// Exposer la fonction globalement pour test dans la console
if (typeof window !== 'undefined') {
  (window as any).testNotificationSound = () => {
    playNotificationSound()
  }
}

