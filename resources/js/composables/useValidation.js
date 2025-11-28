import validator from 'validator'

export function useValidation() {
  const validateField = (value, rules) => {
    const errors = []
    
    for (const rule of rules) {
      const { type, message, params = {} } = rule
      
      switch (type) {
        case 'required':
          if (!value || (typeof value === 'string' && value.trim() === '')) {
            errors.push(message || 'Ce champ est requis')
          }
          break
          
        case 'email':
          if (value && !validator.isEmail(value)) {
            errors.push(message || 'Adresse email invalide')
          }
          break
          
        case 'minLength':
          if (value && value.length < params.min) {
            errors.push(message || `Minimum ${params.min} caractères requis`)
          }
          break
          
        case 'maxLength':
          if (value && value.length > params.max) {
            errors.push(message || `Maximum ${params.max} caractères autorisés`)
          }
          break
          
        case 'numeric':
          if (value && !validator.isNumeric(String(value))) {
            errors.push(message || 'Valeur numérique requise')
          }
          break
          
        case 'decimal':
          if (value && !validator.isDecimal(String(value))) {
            errors.push(message || 'Valeur décimale requise')
          }
          break
          
        case 'min':
          if (value && parseFloat(value) < params.min) {
            errors.push(message || `Valeur minimum: ${params.min}`)
          }
          break
          
        case 'max':
          if (value && parseFloat(value) > params.max) {
            errors.push(message || `Valeur maximum: ${params.max}`)
          }
          break
          
        case 'phone':
          if (value && !validator.isMobilePhone(value)) {
            errors.push(message || 'Numéro de téléphone invalide')
          }
          break
          
        case 'url':
          if (value && !validator.isURL(value)) {
            errors.push(message || 'URL invalide')
          }
          break
          
        case 'alphanumeric':
          if (value && !validator.isAlphanumeric(value)) {
            errors.push(message || 'Caractères alphanumériques uniquement')
          }
          break
          
        case 'custom':
          if (value && !params.validator(value)) {
            errors.push(message || 'Valeur invalide')
          }
          break
      }
    }
    
    return errors
  }
  
  const validateForm = (formData, validationRules) => {
    const errors = {}
    
    for (const [fieldName, rules] of Object.entries(validationRules)) {
      const fieldErrors = validateField(formData[fieldName], rules)
      if (fieldErrors.length > 0) {
        errors[fieldName] = fieldErrors[0] // Prendre seulement la première erreur
      }
    }
    
    return {
      isValid: Object.keys(errors).length === 0,
      errors
    }
  }
  
  // Règles de validation prédéfinies
  const rules = {
    required: (message) => ({ type: 'required', message }),
    email: (message) => ({ type: 'email', message }),
    minLength: (min, message) => ({ type: 'minLength', params: { min }, message }),
    maxLength: (max, message) => ({ type: 'maxLength', params: { max }, message }),
    numeric: (message) => ({ type: 'numeric', message }),
    decimal: (message) => ({ type: 'decimal', message }),
    min: (min, message) => ({ type: 'min', params: { min }, message }),
    max: (max, message) => ({ type: 'max', params: { max }, message }),
    phone: (message) => ({ type: 'phone', message }),
    url: (message) => ({ type: 'url', message }),
    alphanumeric: (message) => ({ type: 'alphanumeric', message }),
    custom: (validator, message) => ({ type: 'custom', params: { validator }, message })
  }
  
  return {
    validateField,
    validateForm,
    rules
  }
}
